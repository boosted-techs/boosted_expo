#!/bin/bash
set -euo pipefail
umask 077
if ! id boosted-one >/dev/null 2>&1; then
    useradd --create-home --home-dir /srv/boosted-one --shell /bin/bash boosted-one
fi
app_uid=$(id -u boosted-one)
if ! mountpoint -q /srv/boosted-one; then
    if [ -e /srv/boosted-one-disk.img ]; then
        echo 'Existing unmounted disk requires inspection.' >&2
        exit 1
    fi
    test -z "$(find /srv/boosted-one -mindepth 1 -maxdepth 1 ! -name '.bash*' ! -name '.profile' -print -quit)"
    fallocate -l 12G /srv/boosted-one-disk.img
    chmod 600 /srv/boosted-one-disk.img
    mkfs.ext4 -q -m 0 /srv/boosted-one-disk.img
    printf '/srv/boosted-one-disk.img /srv/boosted-one ext4 loop,nodev,nosuid,defaults 0 2\n' >> /etc/fstab
    mount /srv/boosted-one
fi
chown boosted-one:boosted-one /srv/boosted-one
chmod 750 /srv/boosted-one
install -d -o boosted-one -g boosted-one -m 700 /srv/boosted-one/shared /srv/boosted-one/releases
install -d -m 755 /var/www/boosted-one-acme/.well-known/acme-challenge
mkdir -p /etc/systemd/system/user-${app_uid}.slice.d /etc/systemd/system/user@${app_uid}.service.d
cat > /etc/systemd/system/user-${app_uid}.slice.d/limits.conf <<'EOF'
[Slice]
CPUQuota=150%
MemoryHigh=2200M
MemoryMax=2560M
MemorySwapMax=0
TasksMax=1024
IOWeight=10
EOF
cat > /etc/systemd/system/user@${app_uid}.service.d/isolation.conf <<'EOF'
[Unit]
RequiresMountsFor=/srv/boosted-one
Requires=boosted-one-isolation.service
After=boosted-one-isolation.service
EOF
cat > /usr/local/sbin/boosted-one-isolation <<'EOF'
#!/bin/sh
set -eu
app_uid=$(id -u boosted-one)
for tool in iptables ip6tables; do
    $tool -N BOOSTED_ONE_EGRESS 2>/dev/null || true
    $tool -F BOOSTED_ONE_EGRESS
    $tool -A BOOSTED_ONE_EGRESS -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
done
# Allow only the local DNS stub; other loopback and private traffic is denied.
for transport in udp tcp; do
    iptables -A BOOSTED_ONE_EGRESS -d 127.0.0.53/32 -p "$transport" --dport 53 -j RETURN
    iptables -A BOOSTED_ONE_EGRESS -d 127.0.0.54/32 -p "$transport" --dport 53 -j RETURN
done
for destination in 0.0.0.0/8 127.0.0.0/8 10.0.0.0/8 100.64.0.0/10 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16 224.0.0.0/4 240.0.0.0/4 51.79.84.85/32; do
    iptables -A BOOSTED_ONE_EGRESS -d "$destination" -j REJECT
done
for destination in ::/128 ::1/128 ::ffff:0:0/96 64:ff9b::/96 64:ff9b:1::/48 2002::/16 fc00::/7 fe80::/10 ff00::/8 2607:5300:205:200::1b27/128; do
    ip6tables -A BOOSTED_ONE_EGRESS -d "$destination" -j REJECT
done
for tool in iptables ip6tables; do
    $tool -A BOOSTED_ONE_EGRESS -p tcp -m multiport --dports 80,443,465,587 -j RETURN
    $tool -A BOOSTED_ONE_EGRESS -p udp --dport 53 -j RETURN
    $tool -A BOOSTED_ONE_EGRESS -p tcp --dport 53 -j RETURN
    $tool -A BOOSTED_ONE_EGRESS -j REJECT
    $tool -C OUTPUT -m owner --uid-owner "$app_uid" -j BOOSTED_ONE_EGRESS 2>/dev/null || $tool -I OUTPUT 1 -m owner --uid-owner "$app_uid" -j BOOSTED_ONE_EGRESS
done
EOF
chmod 750 /usr/local/sbin/boosted-one-isolation
cat > /etc/systemd/system/boosted-one-isolation.service <<EOF
[Unit]
Description=Restrict BoostedOne egress to protect adjacent services
After=network-online.target
Before=user@${app_uid}.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/boosted-one-isolation
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now boosted-one-isolation.service
systemctl set-property user-${app_uid}.slice CPUQuota=150% MemoryHigh=2200M MemoryMax=2560M MemorySwapMax=0 TasksMax=1024 IOWeight=10
loginctl enable-linger boosted-one
systemctl start user@${app_uid}.service
runuser -u boosted-one -- env HOME=/srv/boosted-one XDG_RUNTIME_DIR=/run/user/${app_uid} DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/${app_uid}/bus dockerd-rootless-setuptool.sh install
runuser -u boosted-one -- env HOME=/srv/boosted-one DOCKER_HOST=unix:///run/user/${app_uid}/docker.sock docker info --format '{{json .SecurityOptions}} {{.CgroupVersion}} {{.CgroupDriver}}'
echo "BoostedOne provisioned with uid ${app_uid}, 12 GiB disk and 2.5 GiB memory ceiling."
