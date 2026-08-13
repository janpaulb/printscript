"""
Guards for the shell script.

macOS ships bash 3.2.  Two of its habits have already broken run.sh once:

  * outside a UTF-8 locale it counts the bytes of a non-ASCII character as
    part of a variable name, so `"$interpreter…"` becomes a lookup of a
    variable named `interpreter…` — which `set -u` then aborts on;
  * `set -e` combined with `set -o pipefail` kills the script on a pipeline
    whose last command exits non-zero, even when that is expected.

Neither reproduces on the bash 5 of a Linux CI runner, so these tests check
the source instead of the behaviour.
"""

from __future__ import annotations

import os
import re
import subprocess

import pytest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCRIPTS = ['run.sh']

# $name or ${name} immediately followed by a byte outside ASCII.
_EXPANSION_THEN_NON_ASCII = re.compile(
    r'\$\{?[A-Za-z_][A-Za-z0-9_]*\}?[^\x00-\x7F]')


def read(script: str) -> str:
    with open(os.path.join(ROOT, script), encoding='utf-8') as handle:
        return handle.read()


@pytest.mark.parametrize('script', SCRIPTS)
def test_the_script_parses(script):
    result = subprocess.run(['bash', '-n', os.path.join(ROOT, script)],
                            capture_output=True, text=True)
    assert result.returncode == 0, result.stderr


@pytest.mark.parametrize('script', SCRIPTS)
def test_the_script_is_executable(script):
    assert os.access(os.path.join(ROOT, script), os.X_OK)


@pytest.mark.parametrize('script', SCRIPTS)
def test_no_variable_is_followed_by_a_non_ascii_character(script):
    """
    Write `${name}...` rather than `$name…`: bash 3.2 in a non-UTF-8 locale
    swallows the following bytes into the variable name.
    """
    offenders = []
    for number, line in enumerate(read(script).splitlines(), start=1):
        if line.lstrip().startswith('#'):
            continue
        match = _EXPANSION_THEN_NON_ASCII.search(line)
        if match:
            offenders.append('%s:%d: %s' % (script, number, line.strip()))

    assert not offenders, (
        'Een variabele wordt direct gevolgd door een niet-ASCII teken; '
        'gebruik ${naam} en ASCII-leestekens:\n' + '\n'.join(offenders))


@pytest.mark.parametrize('script', SCRIPTS)
def test_functions_survive_being_called_without_arguments(script):
    """Every `local x="$1"` needs a default, or `set -u` aborts the script."""
    bare = [line.strip() for line in read(script).splitlines()
            if re.search(r'local\s+\w+="\$\d"\s*$', line.strip())]

    assert not bare, ('Gebruik ${1:-standaardwaarde}, anders breekt set -u af '
                      'als de functie zonder argument wordt aangeroepen:\n'
                      + '\n'.join(bare))
