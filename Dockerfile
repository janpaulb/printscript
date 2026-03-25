# ── Stage 1: Python dependencies ──────────────────────────────────────────────
FROM python:3.11-slim AS deps

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt


# ── Stage 2: Runtime image ─────────────────────────────────────────────────────
FROM python:3.11-slim

# libreoffice-writer    – the Writer application
# libreoffice-headless  – the svp/headless VCL renderer (no display needed)
# fonts-liberation      – metric-compatible Arial/Times/Courier replacements
#                         (prevents text reflow vs. the original Word document)
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        libreoffice-writer \
        libreoffice-headless \
        fonts-liberation \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy installed Python packages from the deps stage
COPY --from=deps /usr/local/lib/python3.11/site-packages \
                 /usr/local/lib/python3.11/site-packages
COPY --from=deps /usr/local/bin /usr/local/bin

# Copy application source
COPY app.py processor.py gdocs.py updater.py ./
COPY templates/ templates/
COPY static/    static/

# Non-root user — LibreOffice should not run as root
RUN useradd -m -u 1001 printscript \
 && chown -R printscript:printscript /app
USER printscript

EXPOSE 5000

# Verify LibreOffice works at build time so broken images are caught early
RUN SAL_USE_VCLPLUGIN=svp libreoffice --headless --version

CMD ["python", "app.py"]
