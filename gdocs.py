"""
Google Docs downloader.

Extracts a document ID from a Google Docs URL and downloads the file
as a .docx (via the Drive export endpoint).

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
import requests


# ---------------------------------------------------------------------------
# URL parsing
# ---------------------------------------------------------------------------

_DOC_ID_RE = re.compile(
    r'(?:'
    r'docs\.google\.com/document/d/'       # Google Docs URL
    r'|drive\.google\.com/file/d/'         # Drive file URL
    r')'
    r'([a-zA-Z0-9_-]+)'
)

_DRIVE_OPEN_RE = re.compile(r'drive\.google\.com/open\?.*id=([a-zA-Z0-9_-]+)')


def extract_doc_id(url: str) -> str:
    """
    Return the Google Docs document ID from a URL.
    Raises ValueError if the URL doesn't look like a Google Docs link.
    """
    url = url.strip()
    for pattern in [_DOC_ID_RE, _DRIVE_OPEN_RE]:
        m = pattern.search(url)
        if m:
            return m.group(1)
    raise ValueError(
        'Geen geldig Google Docs-link. Zorg dat de URL er zo uitziet:\n'
        'https://docs.google.com/document/d/<ID>/edit'
    )


# ---------------------------------------------------------------------------
# Download
# ---------------------------------------------------------------------------

_EXPORT_URL = 'https://docs.google.com/document/d/{doc_id}/export?format=docx'

_HEADERS = {
    'User-Agent': 'PrintScript/1.0',
}


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
    ValueError  – bad URL
    PermissionError – document is private and no token was provided
    RuntimeError    – download failed for another reason
    """
    doc_id = extract_doc_id(url)
    export_url = _EXPORT_URL.format(doc_id=doc_id)

    token = access_token or os.environ.get('GOOGLE_ACCESS_TOKEN')

    headers = dict(_HEADERS)
    if token:
        headers['Authorization'] = f'Bearer {token}'

    try:
        response = requests.get(export_url, headers=headers, timeout=60, allow_redirects=True)
    except requests.RequestException as exc:
        raise RuntimeError(f'Netwerk fout bij ophalen document: {exc}') from exc

    if response.status_code == 401:
        raise PermissionError(
            'Document is privé. Deel het document via "Iedereen met de link" '
            'of geef een OAuth-token mee.'
        )
    if response.status_code == 403:
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

    # Google sometimes returns an HTML login/error page when auth is required
    content_type = response.headers.get('Content-Type', '')
    if 'text/html' in content_type:
        raise PermissionError(
            'Document is privé of de link klopt niet. '
            'Deel het document via "Iedereen met de link kan bekijken".'
        )

    with open(dest_path, 'wb') as fh:
        fh.write(response.content)

    return dest_path
