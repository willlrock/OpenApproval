#!/usr/bin/env python3
"""Fail before activation if the installed site cannot resolve release paths."""
from pathlib import Path
import re
import sys

configuration = re.sub(r"#.*", "", sys.stdin.read())
root = re.escape(str(Path(sys.argv[1]) / "public"))
valid = False
for match in re.finditer(r"\bserver\s*\{", configuration):
    start, depth, end = match.end(), 1, match.end()
    while depth and end < len(configuration):
        depth += (configuration[end] == "{") - (configuration[end] == "}")
        end += 1
    block = configuration[start:end]
    if re.search(r"\broot\s+" + root + r"\s*;", block):
        filenames = re.findall(r"fastcgi_param\s+SCRIPT_FILENAME\s+([^;]+);", block)
        if not filenames or any(value.strip() != "$realpath_root$fastcgi_script_name" for value in filenames):
            sys.exit("Site must use fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name before release deployment.")
        valid = True
if not valid:
    sys.exit("Cannot verify the installed nginx public root. Existing site was not changed.")
