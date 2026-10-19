#!/usr/bin/env python3
"""Offline PE32+ scanner for exact GD 2.209 Windows original binary.

Extracts only metadata and URLs, never patches/executes/repackages the EXE.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import struct
from pathlib import Path

EXPECTED_SHA256 = '524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8'
EXPECTED_SIZE = 13_158_400
API_RE = re.compile(rb'https?://(?:www\.)?(?:boomlings\.com|geometrydash\.com)/(?:database|databas)/[A-Za-z0-9._/-]+\.php')


def read_pe_sections(raw: bytes):
    """Return (image_base, .rdata file offset, .rdata bytes)."""
    if len(raw) < 0x100 or raw[:2] != b'MZ':
        raise ValueError('not an MZ executable')
    pe_off, = struct.unpack_from('<I', raw, 0x3c)
    if pe_off + 24 > len(raw) or raw[pe_off:pe_off+4] != b'PE\x00\x00':
        raise ValueError('bad PE signature')
    machine, number_of_sections = struct.unpack_from('<HH', raw, pe_off+4)
    optional_size, = struct.unpack_from('<H', raw, pe_off+20)
    if machine != 0x8664:
        raise ValueError('not x64 PE (machine 0x8664 required)')
    opt = pe_off+24
    if opt+optional_size > len(raw):
        raise ValueError('truncated optional header')
    magic, = struct.unpack_from('<H', raw, opt)
    if magic != 0x20b:
        raise ValueError('not PE32+')
    image_base, = struct.unpack_from('<Q', raw, opt+24)
    secstart = opt+optional_size
    for i in range(number_of_sections):
        entry = secstart + i*40
        if entry+40 > len(raw):
            raise ValueError('truncated section table')
        name = raw[entry:entry+8].rstrip(b'\x00')
        if name != b'.rdata':
            continue
        virtual_address, = struct.unpack_from('<I', raw, entry+12)
        raw_size, raw_offset = struct.unpack_from('<II', raw, entry+16)
        if not raw_size or raw_offset+raw_size > len(raw):
            raise ValueError('invalid .rdata section bounds')
        return image_base, raw_offset, virtual_address, raw[raw_offset:raw_offset+raw_size]
    raise ValueError('missing .rdata section')


def scan(raw: bytes) -> dict:
    base, file_offset, rva, section = read_pe_sections(raw)
    urls = []
    for m in API_RE.finditer(section):
        url = m.group(0).decode('ascii')
        urls.append({'url': url, 'file_offset': file_offset+m.start(),
                     'virtual_address': '0x%x' % (base+rva+m.start())})
    names = sorted(set(u['url'].rsplit('/',1)[-1] for u in urls))
    sha = hashlib.sha256(raw).hexdigest()
    return {'sha256': sha, 'matches_known_2_209_build': sha == EXPECTED_SHA256,
            'size_bytes': len(raw), 'matches_known_size': len(raw) == EXPECTED_SIZE,
            'image_base': '0x%x' % base, 'url_count': len(urls),
            'unique_php_names': names, 'urls': urls}


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('exe', type=Path)
    p.add_argument('--output', type=Path, default=Path('evidence/binary_scan.json'))
    p.add_argument('--require-known-build', action='store_true')
    a = p.parse_args()
    raw = a.exe.read_bytes()
    result = scan(raw)
    if a.require_known_build and not result['matches_known_2_209_build']:
        p.error('SHA256 is not the verified 2.209 build; refusing exact-build assumptions')
    a.output.parent.mkdir(parents=True, exist_ok=True)
    a.output.write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    print('known_build=%s URLs=%d unique_php_names=%d output=%s' %
          (result['matches_known_2_209_build'], result['url_count'],len(result['unique_php_names']),a.output))

if __name__ == '__main__':
    main()
