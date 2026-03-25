"""
Google Docs downloader.

Extracts a document ID from a Google Docs URL and downloads the file
as a .docx via the Drive export endpoint.

Supported URL patterns:
  https://docs.google.com/document/d/{ID}/edit
  https://docs.google.com/document/d/{ID}/edit?usp=sharing
  https://docs.google.com/document/d/{ID}/view
  https://docs.google.com/document/d/{ID}/
  https://drive.google.com/file/d/{ID}/view
  https://drive.google.com/open?id={ID}

Authentication:
  - Documents shared as "Anyone with the link can view" work without a key.
  - Private documents require a Google OAuth token passed via the
    GOOGLE_ACCESS_TOKEN environment variable, or supplied at call time.
"""

import os
import re
from urllib.parse import urlparse, parse_qs
import requests


# ---------------------------------------------------------------------------
# URL parsing
# ---------------------------------------------------------------------------

_DOC_ID_RE = re.compile(
    r'(?:docs\.google\.com/document/d/|drive\.google\.com/file/d/)'
    r'([a-zA-Z0-9_-]+)'
)


def extract_doc_id(url: str) -> str:
    """
    Return the Google Docs document ID from a URL.
    Raises ValueError if the URL doesn't look like a Google Docs link.
    """
    url = url.strip()

    # Pattern 1: /document/d/<ID>/ or /file/d/<ID>/
    m = _DOC_ID_RE.search(url)
    if m:
        return m.group(1)

    # Pattern 2: drive.google.com/open?id=<ID> (id= can be anywhere in query)
    parsed = urlparse(url)
    if 'drive.google.com' in parsed.netloc and parsed.path == '/open':
        params = parse_qs(parsed.query)
        if 'id' in params:
            return params['id'][0]

    raise ValueError(
        'Geen geldig Google Docs-link. Zorg dat de URL er zo uitziet:\n'
        'https://docs.google.com/document/d/<ID>/edit'
    )


# ---------------------------------------------------------------------------
# Download
# ---------------------------------------------------------------------------

_EXPORT_URL = 'https://docs.google.com/document/d/{doc_id}/export?format=docx'
# Google adds a virus-scan confirmation page for large files; this bypasses it
_EXPORT_URL_CONFIRM = (
    'https://docs.google.com/document/d/{doc_id}/export?format=docx&confirm=t'
)

_MAX_DOWNLOAD_BYTES = 50 * 1024 * 1024  # 50 MB

_HEADERS = {'User-Agent': 'PrintScript/1.0'}


def download_as_docx(url: str, dest_path: str, access_token: str | None = None) -> str:
    """
    Download a Google Docs document as a .docx file.

    Parameters
    ----------
    url          : Google Docs URL
    dest_path    : Destination file path (will be created/overwritten)
    access_token : Optional OAuth2 access token for private documents.
                   Falls back to the GOOGLE_ACCESS_TOKEN env var.

    Returns
    -------
    dest_path on success.

    Raises
    ------
    ValueError      – bad URL or document not found
    PermissionError – document is private and no token was provided
    RuntimeError    – download failed for another reason
    """
    doc_id = extract_doc_id(url)
    token = access_token or os.environ.get('GOOGLE_ACCESS_TOKEN')

    headers = dict(_HEADERS)
    if token:
        headers['Authorization'] = f'Bearer {token}'

    response = _get_export(doc_id, headers)

    # Large files get a virus-scan confirmation page; retry with confirm=t
    content_type = response.headers.get('Content-Type', '')
    if response.ok and 'text/html' in content_type:
        response = _get_export(doc_id, headers, confirm=True)
        content_type = response.headers.get('Content-Type', '')

    _check_response(response, content_type)
    _stream_to_file(response, dest_path)
    return dest_path


def _get_export(doc_id: str, headers: dict, confirm: bool = False) -> requests.Response:
    template = _EXPORT_URL_CONFIRM if confirm else _EXPORT_URL
    export_url = template.format(doc_id=doc_id)
    try:
        return requests.get(
            export_url,
            headers=headers,
            timeout=60,
            allow_redirects=True,
            stream=True,
        )
    except requests.RequestException as exc:
        raise RuntimeError(f'Netwerk fout bij ophalen document: {exc}') from exc


def _check_response(response: requests.Response, content_type: str) -> None:
    if response.status_code in (401, 403):
        raise PermissionError(
            'Toegang geweigerd. Zorg dat het document openbaar gedeeld is '
            '("Iedereen met de link kan bekijken").'
        )
    if response.status_code == 404:
        raise ValueError('Document niet gevonden. Controleer de link.')
    if not response.ok:
        raise RuntimeError(
            f'Google Drive antwoordde met HTTP {response.status_code}.'
        )
    if 'text/html' in content_type:
        raise PermissionError(
            'Document is privé of de link klopt niet. '
            'Deel het document via "Iedereen met de link kan bekijken".'
        )


def _stream_to_file(response: requests.Response, dest_path: str) -> None:
    """Write a streaming response to disk, enforcing the size limit."""
    total = 0
    with open(dest_path, 'wb') as fh:
        for chunk in response.iter_content(chunk_size=65536):
            total += len(chunk)
            if total > _MAX_DOWNLOAD_BYTES:
                raise RuntimeError(
                    f'Document is groter dan de toegestane limiet van '
                    f'{_MAX_DOWNLOAD_BYTES // (1024 * 1024)} MB.'
                )
            fh.write(chunk)
