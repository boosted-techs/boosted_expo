#!/bin/bash
set -euo pipefail
umask 077
changed=0
for service in postgres redis; do
    target=/srv/boosted-one/shared/tls/${service}/server.crt
    if ! openssl x509 -checkend 2592000 -noout -in "$target" >/dev/null; then
        generated=/root/boosted-one-pki/${service}.crt.new
        openssl x509 -req -in /root/boosted-one-pki/${service}.csr \
            -CA /root/boosted-one-pki/ca.crt -CAkey /root/boosted-one-pki/ca.key \
            -CAserial /root/boosted-one-pki/ca.srl \
            -out "$generated" -days 365 \
            -extfile /root/boosted-one-pki/${service}.ext
        # Publish using the service UID so a writable-home symlink cannot turn
        # certificate maintenance into a privileged host-file write.
        cat "$generated" | runuser -u boosted-one -- sh -c \
            'umask 077; cat > "$1.new"; chmod 644 "$1.new"; mv "$1.new" "$1"' sh "$target"
        rm "$generated"
        changed=1
    fi
done
if [ "$changed" -eq 1 ]; then
    app_uid=$(id -u boosted-one)
    runuser -u boosted-one -- env HOME=/srv/boosted-one DOCKER_HOST=unix:///run/user/${app_uid}/docker.sock \
        docker compose -f /srv/boosted-one/current/deploy/compose.production.yaml restart postgres redis
fi
echo 'BoostedOne internal TLS validity checked.'
