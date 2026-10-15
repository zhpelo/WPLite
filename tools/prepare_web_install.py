#!/usr/bin/env python3
"""Generate an owner setup key locally; never print the secret to console logs."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets


def prepare(directory: Path) -> dict:
    directory = directory.absolute()
    # New private directory only. No overwrites and no pre-existing symlink.
    directory.mkdir(mode=0o700)
    secret = secrets.token_hex(32)
    php = ("<?php\nif (!defined('WPLITE_SETUP_READING')) { http_response_code(404); exit; }\n"
           + "return ['sha256' => '" + hashlib.sha256(secret.encode()).hexdigest() + "'];\n")
    files = {'setup-key.php': php,
             'owner-secret.txt': secret + '\nUpload ONLY setup-key.php. Never upload this owner-secret.txt file.\n'}
    for name, content in files.items():
        path = directory / name
        with open(path, 'x', opener=lambda name, flags: os.open(name, flags, 0o600)) as output:
            output.write(content)
    return {'upload_file': str(directory / 'setup-key.php'), 'local_secret_file': str(directory / 'owner-secret.txt'),
            'expires': 'one hour after the uploaded key file modification time'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, required=True, help='New private LOCAL directory; never upload the entire directory')
    args = parser.parse_args()
    print(json.dumps(prepare(args.directory), indent=2))


if __name__ == '__main__':
    main()
