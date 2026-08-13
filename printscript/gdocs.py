"""
Google Docs downloader.

Turns a Google Docs share link into the document's .docx export.  Only the
document id is ever taken from the URL and the export endpoint is built from
scratch, so a pasted link can never make the server call something else.

Documents shared as "anyone with the link can view" work without credentials.
A private document needs an OAuth access token (``GOOGLE_ACCESS_TOKEN``, or
passed in per request).
"""

from __future__ import annotations

import os
import re
from dataclasses import dataclass
from typing import Optional
from urllib.parse import parse_qs, unquote, urlparse

import requests

MAX_DOWNLOAD_BYTES = 50 * 1024 * 1024
CONNECT_TIMEOUT = 10
READ_TIMEOUT = 120

_EXPORT_URL = 'https://docs.google.com/document/d/{doc_id}/export'
_USER_AGENT = 'PrintScript/2.0 (+https://github.com/janpaulb/printscript)'

_ID_PATTERN = r'[a-zA-Z0-9_-]{12,}'
_PATH_PATTERNS = (
    re.compile(r'docs\.google\.com/document/d/(' + _ID_PATTERN + r')'),
    re.compile(r'docs\.google\.com/document/u/\d+/d/(' + _ID_PATTERN + r')'),
    re.compile(r'drive\.google\.com/file/d/(' + _ID_PATTERN + r')'),
    re.compile(r'drive\.google\.com/drive/.*?/(' + _ID_PATTERN + r')'),
)
_BARE_ID = re.compile(r'^' + _ID_PATTERN + r'$')

_HELP = ('Plak de deel-link van je document, bijvoorbeeld:\n'
         'https://docs.google.com/document/d/<document-id>/edit')


class GoogleDocsError(RuntimeError):
    """Any failure while fetching a document from Google."""


class DocumentAccessError(GoogleDocsError):
    """The document exists but is not readable with the credentials we have."""


@dataclass
class DownloadedDocument:
    data: bytes
    doc_id: str
    title: Optional[str] = None


def extract_doc_id(url: str) -> str:
    """Pull the document id out of any of Google's link shapes."""
    if not url or not url.strip():
        raise ValueError('Geen link opgegeven.\n' + _HELP)
    url = url.strip()

    if _BARE_ID.match(url):
        return url

    if '/document/d/e/' in url or '/document/u/0/d/e/' in url:
        raise ValueError(
            'Dit is een "gepubliceerd op internet"-link. Gebruik de gewone '
            'deel-link van het document (Delen → Link kopiëren).\n' + _HELP)

    for pattern in _PATH_PATTERNS:
        match = pattern.search(url)
        if match:
            return match.group(1)

    parsed = urlparse(url if '//' in url else 'https://' + url)
    if 'google.com' in parsed.netloc:
        params = parse_qs(parsed.query)
        for key in ('id', 'docid', 'srcid'):
            values = params.get(key)
            if values and _BARE_ID.match(values[0]):
                return values[0]

    raise ValueError('Dit lijkt geen Google Docs-link te zijn.\n' + _HELP)


def download(url: str, access_token: Optional[str] = None,
             session: Optional[requests.Session] = None) -> DownloadedDocument:
    """Download a Google document as .docx bytes."""
    doc_id = extract_doc_id(url)
    token = access_token or os.environ.get('GOOGLE_ACCESS_TOKEN') or None
    http = session or requests

    headers = {'User-Agent': _USER_AGENT}
    if token:
        headers['Authorization'] = 'Bearer %s' % token

    response = _get(http, _EXPORT_URL.format(doc_id=doc_id), headers,
                    params={'format': 'docx'})
    with response:
        _raise_for_status(response, bool(token))
        data = _read_limited(response)

    if not data[:2] == b'PK':
        raise DocumentAccessError(
            'Google gaf geen document terug. Zet het document op '
            '"Iedereen met de link kan bekijken", of gebruik een account met '
            'toegang.')

    return DownloadedDocument(data=data, doc_id=doc_id,
                              title=_filename_from_response(response))


# ── Internals ────────────────────────────────────────────────────────────────

def _get(http, url: str, headers: dict, params: dict):
    try:
        return http.get(url, headers=headers, params=params, stream=True,
                        allow_redirects=True,
                        timeout=(CONNECT_TIMEOUT, READ_TIMEOUT))
    except requests.Timeout as exc:
        raise GoogleDocsError(
            'Google reageerde niet op tijd. Probeer het opnieuw.') from exc
    except requests.RequestException as exc:
        raise GoogleDocsError('Kan Google Docs niet bereiken: %s' % exc) from exc


def _raise_for_status(response, authenticated: bool) -> None:
    status = response.status_code
    if status in (401, 403):
        raise DocumentAccessError(
            'Geen toegang tot dit document. Deel het via "Iedereen met de link '
            'kan bekijken"%s.'
            % ('' if authenticated else ', of log in met een account dat toegang heeft'))
    if status == 404:
        raise DocumentAccessError(
            'Document niet gevonden. Controleer of de link klopt en of het '
            'document niet verwijderd is.')
    if status == 429:
        raise GoogleDocsError(
            'Google heeft de aanvraag tijdelijk geblokkeerd (te veel verzoeken). '
            'Probeer het over een minuut opnieuw.')
    if status >= 500:
        raise GoogleDocsError('Google gaf een serverfout (HTTP %d). Probeer het '
                              'later opnieuw.' % status)
    if not response.ok:
        raise GoogleDocsError('Google antwoordde met HTTP %d.' % status)

    content_type = (response.headers.get('Content-Type') or '').lower()
    if 'text/html' in content_type:
        raise DocumentAccessError(
            'Het document is niet openbaar. Google stuurde een inlogpagina in '
            'plaats van het document. Zet het op "Iedereen met de link kan '
            'bekijken".')


def _read_limited(response) -> bytes:
    chunks = []
    total = 0
    for chunk in response.iter_content(chunk_size=65536):
        if not chunk:
            continue
        total += len(chunk)
        if total > MAX_DOWNLOAD_BYTES:
            raise GoogleDocsError(
                'Het document is groter dan de limiet van %d MB.'
                % (MAX_DOWNLOAD_BYTES // (1024 * 1024)))
        chunks.append(chunk)
    if not chunks:
        raise GoogleDocsError('Google stuurde een leeg document terug.')
    return b''.join(chunks)


_FILENAME_STAR = re.compile(r"filename\*\s*=\s*[^']*'[^']*'([^;]+)", re.IGNORECASE)
_FILENAME_PLAIN = re.compile(r'filename\s*=\s*"([^"]+)"|filename\s*=\s*([^;]+)',
                             re.IGNORECASE)


def _filename_from_response(response) -> Optional[str]:
    """Google puts the document's own title in Content-Disposition."""
    disposition = response.headers.get('Content-Disposition') or ''
    if not disposition:
        return None

    match = _FILENAME_STAR.search(disposition)
    if match:
        name = unquote(match.group(1).strip())
    else:
        match = _FILENAME_PLAIN.search(disposition)
        if not match:
            return None
        name = (match.group(1) or match.group(2) or '').strip()

    if name.lower().endswith('.docx'):
        name = name[:-5]
    return name or None


__all__ = [
    'DocumentAccessError',
    'DownloadedDocument',
    'GoogleDocsError',
    'MAX_DOWNLOAD_BYTES',
    'download',
    'extract_doc_id',
]
