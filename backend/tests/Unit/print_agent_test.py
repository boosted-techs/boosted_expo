"""No printer or external network is contacted by these workstation transport tests."""
import importlib.util
import pathlib
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('agent', pathlib.Path(__file__).parents[2] / 'resources/printing/zebra_agent.py')
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)

class WorkstationTransportTest(unittest.TestCase):
    def packet(self):
        raw = '^XA\n^XZ\n'
        return {'job': {'job_id': 7, 'lease': 'L' * 64, 'sha256': agent.hashlib.sha256(raw.encode()).hexdigest(), 'zpl': raw, 'copies': 2}}

    def test_system_queue_receives_explicit_copies_and_acknowledges_only_after_transfer(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(agent, 'receipt_path', return_value=pathlib.Path(directory)/'receipts.json'), patch.object(agent, 'send_system') as send, patch.object(agent, 'request', side_effect=[self.packet(), {'status':'transferred'}]) as request:
            agent.transfer_one('https://export.example','4','secret',{'transport':'system','printer':'GK888t'})
            send.assert_called_once_with('GK888t', b'^XA\n^XZ\n^XA\n^XZ\n')
            self.assertEqual('/jobs/7/ack',request.call_args.args[3])

    def test_partial_transfer_is_saved_as_uncertain_and_is_never_automatically_repeated(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(agent, 'receipt_path', return_value=pathlib.Path(directory)/'receipts.json'), patch.object(agent, 'send_system', side_effect=OSError('printer disconnected')) as send, patch.object(agent, 'request', return_value=self.packet()):
            with self.assertRaises(OSError):
                agent.transfer_one('https://export.example','4','secret',{'transport':'system','printer':'GK888t'})
            with self.assertRaises(RuntimeError):
                agent.transfer_one('https://export.example','4','secret',{'transport':'system','printer':'GK888t'})
            self.assertEqual(1,send.call_count)

    def test_lost_acknowledgement_does_not_repeat_a_recorded_transfer(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(agent, 'receipt_path', return_value=pathlib.Path(directory)/'receipts.json'), patch.object(agent, 'send_system') as send, patch.object(agent, 'request', side_effect=[self.packet(),OSError('lost acknowledgement'),self.packet(),{'status':'transferred'}]):
            with self.assertRaises(OSError):
                agent.transfer_one('https://export.example','4','secret',{'transport':'system','printer':'GK888t'})
            agent.transfer_one('https://export.example','4','secret',{'transport':'system','printer':'GK888t'})
            self.assertEqual(1,send.call_count)

    def test_mac_printing_uses_local_queue_without_shell_interpolation(self):
        with patch.object(agent.os,'name','posix'),patch.object(agent.subprocess,'run') as run:
            agent.send_system('USB GK888t',b'^XA\n^XZ\n')
            self.assertEqual(['lp','-d','USB GK888t','-o','raw'],run.call_args.args[0])
            self.assertEqual(b'^XA\n^XZ\n',run.call_args.kwargs['input'])
            self.assertNotIn('shell',run.call_args.kwargs)

if __name__ == '__main__':
    unittest.main()
