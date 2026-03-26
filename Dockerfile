# ── Stage 1: Python dependencies ──────────────────────────────────────────────
FROM python:3.11-slim AS deps

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt


# ── Stage 2: Runtime image ─────────────────────────────────────────────────────
FROM python:3.11-slim

# PDF conversion is handled by WeasyPrint + mammoth (pure Python, no display
# or VCL plugin required). We only need system fonts for proper rendering.
#
#   fonts-liberation  — metric-compatible Arial/Times/Courier clones
#                       prevents text reflow vs. the original .docx
#   fonts-noto        — broad Unicode coverage for non-Latin documents
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        fonts-liberation \
        fonts-noto \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy installed Python packages from the deps stage
COPY --from=deps /usr/local/lib/python3.11/site-packages \
                 /usr/local/lib/python3.11/site-packages
COPY --from=deps /usr/local/bin /usr/local/bin

# Copy application source
COPY app.py processor.py gdocs.py ./
COPY templates/ templates/
COPY static/    static/

# Non-root user
RUN useradd -m -u 1001 printscript \
 && chown -R printscript:printscript /app
USER printscript

EXPOSE 5000

# Verify WeasyPrint conversion works at build time
RUN python3 -c "
import tempfile, os, sys
sys.path.insert(0, '/app')
import processor

with tempfile.TemporaryDirectory() as d:
    # Create a minimal DOCX via python-docx
    from docx import Document
    doc = Document()
    doc.add_paragraph('build test')
    src = os.path.join(d, 'test.docx')
    doc.save(src)
    pdf = processor._convert_with_weasyprint(src, d)
    assert os.path.exists(pdf) and os.path.getsize(pdf) > 1024, 'PDF too small or missing'
    print('WeasyPrint conversion test: OK', flush=True)
"

# gunicorn handles graceful shutdown, worker recycling and multi-core use.
COPY gunicorn.conf.py .
CMD ["gunicorn", "--config", "gunicorn.conf.py", "app:app"]
