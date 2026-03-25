"""
PrintScript – Word to print-ready PDF converter.
Flask web application entry point.
"""

import os
import uuid
import tempfile
from pathlib import Path

from flask import (
    Flask,
    render_template,
    request,
    send_file,
    jsonify,
    after_this_request,
)

from processor import process

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 50 * 1024 * 1024  # 50 MB upload limit

ALLOWED_EXTENSIONS = {'.docx'}


def _allowed(filename: str) -> bool:
    return Path(filename).suffix.lower() in ALLOWED_EXTENSIONS


@app.route('/')
def index():
    return render_template('index.html')


@app.route('/convert', methods=['POST'])
def convert():
    if 'file' not in request.files:
        return jsonify(error='Geen bestand ontvangen.'), 400

    file = request.files['file']
    if not file or not file.filename:
        return jsonify(error='Geen bestand geselecteerd.'), 400

    if not _allowed(file.filename):
        return jsonify(error='Alleen .docx bestanden zijn toegestaan.'), 400

    # Save upload to a temp dir, process, then stream PDF back
    tmpdir = tempfile.mkdtemp()
    try:
        original_name = Path(file.filename).stem
        input_path = os.path.join(tmpdir, f'{uuid.uuid4().hex}.docx')
        output_path = os.path.join(tmpdir, f'{original_name}_printscript.pdf')

        file.save(input_path)

        try:
            process(input_path, output_path)
        except Exception as exc:
            return jsonify(error=f'Conversie mislukt: {exc}'), 500

        # Clean up temp files after the response is sent
        @after_this_request
        def cleanup(response):
            try:
                import shutil
                shutil.rmtree(tmpdir, ignore_errors=True)
            except Exception:
                pass
            return response

        return send_file(
            output_path,
            mimetype='application/pdf',
            as_attachment=True,
            download_name=f'{original_name}_printscript.pdf',
        )

    except Exception as exc:
        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)
        return jsonify(error=str(exc)), 500


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)
