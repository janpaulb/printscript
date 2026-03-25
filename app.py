"""
PrintScript – Word to print-ready PDF converter.
Flask web application entry point.
"""

import os
import shutil
import tempfile
import uuid
from pathlib import Path

from flask import (
    Flask,
    after_this_request,
    jsonify,
    render_template,
    request,
    send_file,
)

from processor import process
from gdocs import download_as_docx, extract_doc_id

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 50 * 1024 * 1024  # 50 MB upload limit

ALLOWED_EXTENSIONS = {'.docx'}


def _allowed(filename: str) -> bool:
    return Path(filename).suffix.lower() in ALLOWED_EXTENSIONS


def _stream_pdf(tmpdir: str, pdf_path: str, download_name: str):
    """Register cleanup and return the PDF as a download response."""

    @after_this_request
    def cleanup(response):
        shutil.rmtree(tmpdir, ignore_errors=True)
        return response

    return send_file(
        pdf_path,
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

    tmpdir = tempfile.mkdtemp()
    try:
        stem = Path(file.filename).stem
        input_path = os.path.join(tmpdir, f'{uuid.uuid4().hex}.docx')
        output_path = os.path.join(tmpdir, f'{stem}_printscript.pdf')

        file.save(input_path)
        process(input_path, output_path)

        return _stream_pdf(tmpdir, output_path, f'{stem}_printscript.pdf')

    except Exception as exc:
        shutil.rmtree(tmpdir, ignore_errors=True)
        return jsonify(error=f'Conversie mislukt: {exc}'), 500


@app.route('/convert-url', methods=['POST'])
def convert_url():
    """Convert a Google Docs document referenced by URL."""
    body = request.get_json(silent=True) or {}
    url = (body.get('url') or '').strip()

    if not url:
        return jsonify(error='Geen URL opgegeven.'), 400

    # Validate it looks like a Google Docs link before hitting the network
    try:
        doc_id = extract_doc_id(url)
    except ValueError as exc:
        return jsonify(error=str(exc)), 400

    tmpdir = tempfile.mkdtemp()
    try:
        input_path = os.path.join(tmpdir, f'{doc_id}.docx')
        output_path = os.path.join(tmpdir, f'{doc_id}_printscript.pdf')

        # Optional token forwarded from the browser (future OAuth flow)
        token = body.get('access_token') or None

        try:
            download_as_docx(url, input_path, access_token=token)
        except PermissionError as exc:
            shutil.rmtree(tmpdir, ignore_errors=True)
            return jsonify(error=str(exc)), 403
        except (ValueError, RuntimeError) as exc:
            shutil.rmtree(tmpdir, ignore_errors=True)
            return jsonify(error=str(exc)), 400

        process(input_path, output_path)

        return _stream_pdf(tmpdir, output_path, f'printscript_{doc_id[:8]}.pdf')

    except Exception as exc:
        shutil.rmtree(tmpdir, ignore_errors=True)
        return jsonify(error=f'Conversie mislukt: {exc}'), 500


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)
