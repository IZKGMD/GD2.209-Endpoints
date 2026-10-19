"""No copyrighted executable required: scanner tests use a synthetic PE fixture."""
import sys
import unittest
import struct
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from scan_binary import read_pe_sections, scan


def make_pe(url=b'https://www.boomlings.com/database/getGJThreads.php'):
    d = bytearray(0x600)
    d[:2] = b'MZ'
    struct.pack_into('<I', d, 0x3c, 0x80)
    d[0x80:0x84] = b'PE\x00\x00'
    struct.pack_into('<H', d, 0x84, 0x8664)
    struct.pack_into('<H', d, 0x86, 1)  # one section
    struct.pack_into('<H', d, 0x94, 0xf0)  # optional header size
    struct.pack_into('<H', d, 0x98, 0x20b)
    struct.pack_into('<Q', d, 0x98+24, 0x140000000)
    section_header = 0x98+0xf0
    d[section_header:section_header+8] = b'.rdata\x00\x00'
    struct.pack_into('<I', d, section_header+12, 0x3000)
    struct.pack_into('<II', d, section_header+16, 0x200, 0x400)
    d[0x410:0x410+len(url)] = url
    return bytes(d)


class ScannerTest(unittest.TestCase):
    def test_synthetic_pe_url(self):
        x = scan(make_pe())
        self.assertEqual(1, x['url_count'])
        self.assertEqual('getGJThreads.php', x['unique_php_names'][0])
        self.assertEqual('0x140003010', x['urls'][0]['virtual_address'])
        self.assertFalse(x['matches_known_2_209_build'])

    def test_rejects_invalid(self):
        with self.assertRaises(ValueError):
            read_pe_sections(b'random bytes')

    def test_no_urls(self):
        self.assertEqual(0, scan(make_pe(b'not an API URL'))['url_count'])

if __name__ == '__main__':
    unittest.main()
