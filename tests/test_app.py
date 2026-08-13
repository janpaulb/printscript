"""The HTTP surface."""

from __future__ import annotations

import base64
import io
import json

import fixtures as F
import pytest

import app as app_module
from printscript.gdocs import DocumentAccessError, GoogleDocsError
from printscript.pipeline import ConversionOptions


@pytest.fixture
def docx(builder):
    return builder.build(F.paragraph('Hallo') + F.page_break_paragraph() +
                         F.paragraph('Wereld') + F.DEFAULT_SECTION)


def summary_of(response) -> dict:
    return json.loads(base64.b64decode(response.headers['X-PrintScript-Summary']))


# ── Basics ───────────────────────────────────────────────────────────────────

def test_the_page_loads(client):
    response = client.get('/')
    assert response.status_code == 200
    assert b'PrintScript' in response.data
    assert b'docs.google.com' in response.data


def test_health_endpoint(client):
    response = client.get('/healthz')
    assert response.status_code == 200
    assert response.get_json()['status'] == 'ok'


# ── Upload conversion ────────────────────────────────────────────────────────

def test_uploading_a_docx_returns_a_pdf(client, docx):
    response = client.post('/api/convert', data={
        'file': (io.BytesIO(docx), 'Mijn script.docx'),
    }, content_type='multipart/form-data')

    assert response.status_code == 200
    assert response.mimetype == 'application/pdf'
    assert response.data.startswith(b'%PDF')

    summary = summary_of(response)
    assert summary['pages'] == 2
    assert summary['filename'] == 'Mijn script - printklaar.pdf'
    assert 'filename*=UTF-8' in response.headers['Content-Disposition']


def test_options_travel_with_the_upload(client, docx, monkeypatch):
    captured = {}
    original = app_module.convert_docx

    def spy(data, options=None, title=None):
        captured['options'] = options
        return original(data, options, title)

    monkeypatch.setattr(app_module, 'convert_docx', spy)

    client.post('/api/convert', data={
        'file': (io.BytesIO(docx), 'x.docx'),
        'options': json.dumps({'images_first_page_only': False,
                               'page_numbers_on_first_page': False}),
    }, content_type='multipart/form-data')

    assert captured['options'] == ConversionOptions(
        images_first_page_only=False,
        add_page_numbers=True,
        page_numbers_on_first_page=False)


def test_only_docx_uploads_are_accepted(client):
    response = client.post('/api/convert', data={
        'file': (io.BytesIO(b'%PDF-1.4'), 'script.pdf'),
    }, content_type='multipart/form-data')

    assert response.status_code == 400
    assert '.docx' in response.get_json()['error']


def test_a_corrupt_docx_is_a_client_error(client):
    response = client.post('/api/convert', data={
        'file': (io.BytesIO(b'niet eens een zip'), 'kapot.docx'),
    }, content_type='multipart/form-data')

    assert response.status_code == 400
    assert 'geldig' in response.get_json()['error']


# ── URL conversion ───────────────────────────────────────────────────────────

def test_converting_a_google_docs_url(client, docx, monkeypatch):
    def fake_download(url, access_token=None, session=None):
        from printscript.gdocs import DownloadedDocument
        assert 'docs.google.com' in url
        return DownloadedDocument(data=docx, doc_id='abc123', title='Aflevering 4')

    monkeypatch.setattr('printscript.pipeline.gdocs.download', fake_download)

    response = client.post('/api/convert', json={
        'url': 'https://docs.google.com/document/d/abcdefghijkl/edit',
    })

    assert response.status_code == 200
    assert response.data.startswith(b'%PDF')
    assert summary_of(response)['filename'] == 'Aflevering 4 - printklaar.pdf'


def test_a_missing_url_is_rejected(client):
    response = client.post('/api/convert', json={'url': '  '})
    assert response.status_code == 400
    assert 'link' in response.get_json()['error']


def test_a_bad_url_is_rejected_before_any_network_call(client):
    response = client.post('/api/convert', json={'url': 'https://example.com/doc'})
    assert response.status_code == 400
    assert 'Google Docs' in response.get_json()['error']


def test_a_private_document_answers_403(client, monkeypatch):
    def fake_download(*_args, **_kwargs):
        raise DocumentAccessError('Geen toegang tot dit document.')

    monkeypatch.setattr('printscript.pipeline.gdocs.download', fake_download)
    response = client.post('/api/convert', json={
        'url': 'https://docs.google.com/document/d/abcdefghijkl/edit'})

    assert response.status_code == 403
    assert 'toegang' in response.get_json()['error']


def test_a_google_outage_answers_502(client, monkeypatch):
    def fake_download(*_args, **_kwargs):
        raise GoogleDocsError('Google gaf een serverfout (HTTP 503).')

    monkeypatch.setattr('printscript.pipeline.gdocs.download', fake_download)
    response = client.post('/api/convert', json={
        'url': 'https://docs.google.com/document/d/abcdefghijkl/edit'})

    assert response.status_code == 502


def test_unexpected_failures_answer_json_not_html(client, monkeypatch):
    monkeypatch.setattr(app_module, 'convert_google_doc',
                        lambda *a, **k: (_ for _ in ()).throw(RuntimeError('boem')))

    response = client.post('/api/convert', json={
        'url': 'https://docs.google.com/document/d/abcdefghijkl/edit'})

    assert response.status_code == 500
    assert response.mimetype == 'application/json'
    assert 'boem' in response.get_json()['error']


def test_an_unknown_route_answers_json(client):
    response = client.get('/nergens')
    assert response.status_code == 404
    assert response.mimetype == 'application/json'
