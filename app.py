"""
PrintScript – Word to print-ready PDF converter.
Flask web application entry point.
"""

import io
import os
import re
import shutil
import sys
import tempfile
import uuid
from pathlib import Path

from flask import Flask, jsonify, render_template, request, send_file

from processor import process
from gdocs import download_as_docx, extract_doc_id

# When running as a PyInstaller bundle, templates and static files are
# extracted to sys._MEIPASS. main.py sets PRINTSCRIPT_BASE_DIR accordingly.
# In development, __file__ resolves to the project directory.
_base_dir = (
    os.environ.get('PRINTSCRIPT_BASE_DIR')
    or (sys._MEIPASS if getattr(sys, 'frozen', False) else None)  # type: ignore[attr-defined]
    or os.path.dirname(os.path.abspath(__file__))
)

app = Flask(
    __name__,
    template_folder=os.path.join(_base_dir, 'templates'),
    static_folder=os.path.join(_base_dir, 'static'),
)
app.config['MAX_CONTENT_LENGTH'] = 50 * 1024 * 1024  # 50 MB upload limit

ALLOWED_EXTENSIONS = {'.docx'}

# Only allow safe filename characters in the download name
_SAFE_STEM_RE = re.compile(r'[^\w\- ]')


def _safe_stem(raw: str) -> str:
    """Sanitize a filename stem to safe ASCII characters."""
    stem = Path(raw).stem
    stem = _SAFE_STEM_RE.sub('_', stem).strip('_ ')
    return stem or 'document'


def _allowed(filename: str) -> bool:
    return Path(filename).suffix.lower() in ALLOWED_EXTENSIONS


def _pdf_response(pdf_path: str, download_name: str):
    """
    Read the PDF into memory and return it as a download response.

    Reading into memory before returning ensures the temp directory can be
    cleaned up immediately without a race condition against Flask's file
    streaming.
    """
    with open(pdf_path, 'rb') as fh:
        data = fh.read()
    return send_file(
        io.BytesIO(data),
        mimetype='application/pdf',
        as_attachment=True,
        download_name=download_name,
    )


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------

@app.route('/')
def index():
    return render_template('index.html')


@app.route('/convert', methods=['POST'])
def convert():
    """Convert an uploaded .docx file."""
    if 'file' not in request.files:
        return jsonify(error='Geen bestand ontvangen.'), 400

    file = request.files['file']
    if not file or not file.filename:
        return jsonify(error='Geen bestand geselecteerd.'), 400

    if not _allowed(file.filename):
        return jsonify(error='Alleen .docx bestanden zijn toegestaan.'), 400

    stem = _safe_stem(file.filename)
    tmpdir = tempfile.mkdtemp()
    try:
        input_path = os.path.join(tmpdir, f'{uuid.uuid4().hex}.docx')
        output_path = os.path.join(tmpdir, f'{stem}_printscript.pdf')

        file.save(input_path)
        process(input_path, output_path)

        response = _pdf_response(output_path, f'{stem}_printscript.pdf')
    except Exception as exc:
        return jsonify(error=f'Conversie mislukt: {exc}'), 500
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)

    return response


@app.route('/convert-url', methods=['POST'])
def convert_url():
    """Convert a Google Docs document referenced by URL."""
    body = request.get_json(silent=True) or {}
    url = (body.get('url') or '').strip()

    if not url:
        return jsonify(error='Geen URL opgegeven.'), 400

    try:
        doc_id = extract_doc_id(url)
    except ValueError as exc:
        return jsonify(error=str(exc)), 400

    tmpdir = tempfile.mkdtemp()
    try:
        input_path = os.path.join(tmpdir, f'{doc_id}.docx')
        output_path = os.path.join(tmpdir, f'{doc_id}_printscript.pdf')

        token = body.get('access_token') or None
        download_as_docx(url, input_path, access_token=token)
        process(input_path, output_path)

        response = _pdf_response(output_path, f'printscript_{doc_id[:8]}.pdf')
    except PermissionError as exc:
        return jsonify(error=str(exc)), 403
    except (ValueError, RuntimeError) as exc:
        return jsonify(error=str(exc)), 400
    except Exception as exc:
        return jsonify(error=f'Conversie mislukt: {exc}'), 500
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)

    return response


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)
