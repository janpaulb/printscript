# ── Stage 1: Python dependencies ──────────────────────────────────────────────
FROM python:3.11-slim AS deps

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt


# ── Stage 2: Runtime image ─────────────────────────────────────────────────────
FROM python:3.11-slim

# Install LibreOffice and immediately strip everything that is only needed
# for an interactive GUI session. This keeps the image as small as possible.
#
# All cleanup happens in a single RUN so Docker commits one thin layer,
# not the bloated intermediate state before cleanup.
#
# What we keep:
#   libreoffice-writer    – Writer application + OOXML filters
#   libreoffice-headless  – svp VCL renderer (no display needed)
#   fonts-liberation      – metric-compatible Arial/Times/Courier clones
#                           (prevents text reflow vs. the original .docx)
#
# What we remove (~180–220 MB):
#   images_*.zip          – icon themes (6–8 zips × ~15–25 MB each)
#   gallery/              – clipart library (~50 MB)
#   template/             – document templates
#   autocorr/             – autocorrect dictionaries
#   extensions/           – optional LO extensions
#   basic/ wizards/       – Basic IDE and wizard scripts
#   program/classes/      – Java .jar files (Java not needed for conversion)
#   /usr/share/doc/lo*    – upstream documentation
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        libreoffice-writer \
        libreoffice-headless \
        fonts-liberation \
 && find /usr/lib/libreoffice/share/config -name 'images_*.zip' -delete 2>/dev/null || true \
 && rm -rf \
        /usr/lib/libreoffice/share/gallery \
        /usr/lib/libreoffice/share/template \
        /usr/lib/libreoffice/share/autocorr \
        /usr/lib/libreoffice/share/extensions \
        /usr/lib/libreoffice/share/basic \
        /usr/lib/libreoffice/share/wizards \
        /usr/lib/libreoffice/program/classes \
        /usr/share/doc/libreoffice* \
        /var/lib/apt/lists/*

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

# Verify LibreOffice headless works at build time by doing a real conversion
# (not --version, which skips VCL init and always returns 0 even when broken).
RUN python3 -c "
import subprocess, tempfile, os, sys
with tempfile.NamedTemporaryFile(suffix='.txt', delete=False, mode='w') as f:
    f.write('build test')
    src = f.name
env = {**os.environ, 'SAL_USE_VCLPLUGIN': 'svp'}
env.pop('DISPLAY', None); env.pop('WAYLAND_DISPLAY', None)
r = subprocess.run(
    ['libreoffice','--headless','--norestore','--nofirststartwizard',
     '--convert-to','pdf','--outdir','/tmp', src],
    capture_output=True, text=True, timeout=60, env=env)
if r.returncode != 0:
    print('STDOUT:', r.stdout, file=sys.stderr)
    print('STDERR:', r.stderr, file=sys.stderr)
    sys.exit(1)
print('LibreOffice headless VCL test: OK')
"

# gunicorn handles graceful shutdown, worker recycling and multi-core use.
# bootstrap_headless_libreoffice() is called once in the master process via on_starting.
COPY gunicorn.conf.py .
CMD ["gunicorn", "--config", "gunicorn.conf.py", "app:app"]
