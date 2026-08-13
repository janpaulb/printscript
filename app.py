"""
PrintScript — Flask web application.

One endpoint does the work: POST /api/convert accepts either a Google Docs URL
(JSON) or an uploaded .docx (multipart) and answers with the finished PDF.  The
conversion summary rides along in a header so the page can show what was
stripped without a second request.
"""

from __future__ import annotations

import base64
import json
import logging
import os
from urllib.parse import quote

from flask import Flask, Response, jsonify, render_template, request
from werkzeug.exceptions import RequestEntityTooLarge

from printscript import __version__
from printscript.gdocs import DocumentAccessError, GoogleDocsError
from printscript.package import InvalidDocxError
from printscript.pipeline import (MAX_UPLOAD_BYTES, ConversionOptions,
                                  ConversionResult, convert_docx,
                                  convert_google_doc)

log = logging.getLogger(__name__)

ALLOWED_EXTENSIONS = ('.docx',)


def create_app() -> Flask:
    app = Flask(__name__)
    app.config['MAX_CONTENT_LENGTH'] = MAX_UPLOAD_BYTES
    app.config['JSON_AS_ASCII'] = False

    @app.get('/')
    def index():
        return render_template('index.html', version=__version__)

    @app.get('/healthz')
    def healthz():
        return jsonify(status='ok', version=__version__)

    @app.post('/api/convert')
    def convert():
        try:
            options, source = _read_request()
        except ValueError as exc:
            return jsonify(error=str(exc)), 400

        try:
            if source['kind'] == 'url':
                result = convert_google_doc(source['url'], options,
                                            access_token=source.get('access_token'))
            else:
                result = convert_docx(source['data'], options,
                                      title=source.get('filename'))
        except DocumentAccessError as exc:
            return jsonify(error=str(exc)), 403
        except GoogleDocsError as exc:
            return jsonify(error=str(exc)), 502
        except InvalidDocxError as exc:
            return jsonify(error=str(exc)), 400
        except ValueError as exc:
            # An unusable link or an unreadable package: the caller's problem.
            return jsonify(error=str(exc)), 400
        except Exception as exc:                      # noqa: BLE001 — last resort
            log.exception('Conversie mislukt')
            return jsonify(
                error='Conversie mislukt: %s' % exc,
                detail=type(exc).__name__,
            ), 500

        return _pdf_response(result)

    @app.errorhandler(RequestEntityTooLarge)
    def too_large(_error):
        return jsonify(error='Het bestand is groter dan de limiet van %d MB.'
                       % (MAX_UPLOAD_BYTES // (1024 * 1024))), 413

    @app.errorhandler(404)
    def not_found(_error):
        return jsonify(error='Onbekend adres.'), 404

    return app


# ── Request / response plumbing ──────────────────────────────────────────────

def _read_request():
    """Return (ConversionOptions, source) for either request shape."""
    if request.files:
        upload = request.files.get('file')
        if upload is None or not upload.filename:
            raise ValueError('Geen bestand ontvangen.')
        if not upload.filename.lower().endswith(ALLOWED_EXTENSIONS):
            raise ValueError('Alleen .docx-bestanden kunnen worden omgezet. '
                             'Exporteer je document eerst naar .docx.')
        raw_options = request.form.get('options') or '{}'
        try:
            payload = json.loads(raw_options)
        except json.JSONDecodeError:
            payload = {}
        return _options_from(payload), {
            'kind': 'file',
            'data': upload.read(),
            'filename': upload.filename,
        }

    payload = request.get_json(silent=True) or {}
    url = (payload.get('url') or '').strip()
    if not url:
        raise ValueError('Geen Google Docs-link opgegeven.')
    return _options_from(payload.get('options') or payload), {
        'kind': 'url',
        'url': url,
        'access_token': (payload.get('access_token') or '').strip() or None,
    }


def _options_from(payload: dict) -> ConversionOptions:
    defaults = ConversionOptions()
    if not isinstance(payload, dict):
        return defaults
    return ConversionOptions(
        images_first_page_only=_flag(payload, 'images_first_page_only',
                                     defaults.images_first_page_only),
        add_page_numbers=_flag(payload, 'add_page_numbers',
                               defaults.add_page_numbers),
        page_numbers_on_first_page=_flag(payload, 'page_numbers_on_first_page',
                                         defaults.page_numbers_on_first_page),
    )


def _flag(payload: dict, key: str, default: bool) -> bool:
    value = payload.get(key, default)
    if isinstance(value, str):
        return value.lower() not in ('0', 'false', 'no', 'off', '')
    return bool(value)


def _pdf_response(result: ConversionResult) -> Response:
    summary = base64.b64encode(
        json.dumps(result.summary, ensure_ascii=False).encode('utf-8')
    ).decode('ascii')

    response = Response(result.pdf, mimetype='application/pdf')
    response.headers['Content-Disposition'] = (
        "inline; filename=\"%s\"; filename*=UTF-8''%s"
        % (_ascii_filename(result.filename), quote(result.filename))
    )
    response.headers['Content-Length'] = str(len(result.pdf))
    response.headers['X-PrintScript-Summary'] = summary
    response.headers['Cache-Control'] = 'no-store'
    return response


def _ascii_filename(name: str) -> str:
    return name.encode('ascii', 'replace').decode('ascii').replace('"', '')


app = create_app()


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO,
                        format='%(asctime)s %(levelname)s %(name)s: %(message)s')
    app.run(host='0.0.0.0', port=int(os.environ.get('PORT', 5000)), debug=False)
