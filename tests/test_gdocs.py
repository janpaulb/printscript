"""Google Docs link parsing and downloading."""

from __future__ import annotations

import pytest

from printscript import gdocs
from printscript.gdocs import (DocumentAccessError, GoogleDocsError,
                               extract_doc_id)

DOC_ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-x'


# ── URL parsing ──────────────────────────────────────────────────────────────

@pytest.mark.parametrize('url', [
    'https://docs.google.com/document/d/%s/edit' % DOC_ID,
    'https://docs.google.com/document/d/%s/edit?usp=sharing' % DOC_ID,
    'https://docs.google.com/document/d/%s/edit#heading=h.abc123' % DOC_ID,
    'https://docs.google.com/document/d/%s/' % DOC_ID,
    'https://docs.google.com/document/u/1/d/%s/edit' % DOC_ID,
    'https://drive.google.com/file/d/%s/view?usp=drive_link' % DOC_ID,
    'https://drive.google.com/open?id=%s' % DOC_ID,
    'docs.google.com/document/d/%s/edit' % DOC_ID,
    '  https://docs.google.com/document/d/%s/edit  ' % DOC_ID,
    DOC_ID,
])
def test_every_google_link_shape_yields_the_document_id(url):
    assert extract_doc_id(url) == DOC_ID


@pytest.mark.parametrize('url, expected', [
    ('', 'Geen link'),
    ('   ', 'Geen link'),
    ('https://example.com/document/d/abc/edit', 'geen Google Docs-link'),
    ('https://docs.google.com/spreadsheets/d/%s/edit' % DOC_ID, 'geen Google Docs-link'),
])
def test_bad_links_explain_themselves(url, expected):
    with pytest.raises(ValueError) as error:
        extract_doc_id(url)
    assert expected in str(error.value)


def test_a_published_to_web_link_gets_its_own_advice():
    with pytest.raises(ValueError) as error:
        extract_doc_id('https://docs.google.com/document/d/e/2PACX-1vABCdefGH/pub')
    assert 'gepubliceerd' in str(error.value)


# ── Downloading ──────────────────────────────────────────────────────────────

class FakeResponse:
    def __init__(self, status_code=200, headers=None, body=b'', ok=None):
        self.status_code = status_code
        self.headers = headers or {}
        self._body = body
        self.ok = ok if ok is not None else status_code < 400

    def iter_content(self, chunk_size=1):
        for start in range(0, len(self._body), chunk_size):
            yield self._body[start:start + chunk_size]

    def close(self):
        pass

    def __enter__(self):
        return self

    def __exit__(self, *_exc):
        self.close()
        return False


class FakeSession:
    def __init__(self, response):
        self.response = response
        self.calls = []

    def get(self, url, **kwargs):
        self.calls.append((url, kwargs))
        return self.response


def docx_response(**kwargs):
    headers = {
        'Content-Type': 'application/vnd.openxmlformats-officedocument.'
                        'wordprocessingml.document',
        'Content-Disposition': 'attachment; filename="Scenario aflevering 3.docx"',
    }
    headers.update(kwargs.pop('headers', {}))
    return FakeResponse(headers=headers, body=b'PK\x03\x04rest-of-the-zip', **kwargs)


def test_a_shared_document_downloads_with_its_title():
    session = FakeSession(docx_response())

    document = gdocs.download('https://docs.google.com/document/d/%s/edit' % DOC_ID,
                              session=session)

    assert document.data.startswith(b'PK')
    assert document.doc_id == DOC_ID
    assert document.title == 'Scenario aflevering 3'

    url, kwargs = session.calls[0]
    assert url == 'https://docs.google.com/document/d/%s/export' % DOC_ID
    assert kwargs['params'] == {'format': 'docx'}
    assert 'Authorization' not in kwargs['headers']


def test_an_access_token_is_sent_as_a_bearer_header():
    session = FakeSession(docx_response())

    gdocs.download(DOC_ID, access_token='ya29.token', session=session)

    _, kwargs = session.calls[0]
    assert kwargs['headers']['Authorization'] == 'Bearer ya29.token'


def test_a_utf8_filename_header_is_decoded():
    session = FakeSession(docx_response(headers={
        'Content-Disposition': "attachment; filename=\"Sce.docx\"; "
                               "filename*=UTF-8''Sc%C3%A8ne%20%C3%A9t%C3%A9.docx",
    }))

    assert gdocs.download(DOC_ID, session=session).title == 'Scène été'


def test_a_login_page_is_reported_as_no_access():
    session = FakeSession(FakeResponse(headers={'Content-Type': 'text/html; charset=utf-8'},
                                       body=b'<html>Sign in</html>'))

    with pytest.raises(DocumentAccessError) as error:
        gdocs.download(DOC_ID, session=session)
    assert 'niet openbaar' in str(error.value)


@pytest.mark.parametrize('status, exception, fragment', [
    (403, DocumentAccessError, 'Geen toegang'),
    (404, DocumentAccessError, 'niet gevonden'),
    (429, GoogleDocsError, 'te veel verzoeken'),
    (503, GoogleDocsError, 'serverfout'),
])
def test_http_errors_become_readable_dutch(status, exception, fragment):
    session = FakeSession(FakeResponse(status_code=status))

    with pytest.raises(exception) as error:
        gdocs.download(DOC_ID, session=session)
    assert fragment in str(error.value)


def test_something_that_is_not_a_zip_is_rejected():
    session = FakeSession(FakeResponse(
        headers={'Content-Type': 'application/octet-stream'}, body=b'not a docx'))

    with pytest.raises(DocumentAccessError):
        gdocs.download(DOC_ID, session=session)


def test_oversized_documents_are_refused(monkeypatch):
    monkeypatch.setattr(gdocs, 'MAX_DOWNLOAD_BYTES', 16)
    session = FakeSession(docx_response(headers={}))
    session.response._body = b'PK\x03\x04' + b'x' * 1024

    with pytest.raises(GoogleDocsError) as error:
        gdocs.download(DOC_ID, session=session)
    assert 'limiet' in str(error.value)


def test_an_empty_response_is_an_error():
    session = FakeSession(FakeResponse(headers={'Content-Type': 'application/zip'}))

    with pytest.raises(GoogleDocsError) as error:
        gdocs.download(DOC_ID, session=session)
    assert 'leeg' in str(error.value)
