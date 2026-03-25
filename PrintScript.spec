# -*- mode: python ; coding: utf-8 -*-
#
# PyInstaller spec for PrintScript.app
#
# Build with:
#   pyinstaller PrintScript.spec --noconfirm
#
# Or use the provided build_mac.sh script.

block_cipher = None

a = Analysis(
    ['main.py'],
    pathex=['.'],
    binaries=[],
    datas=[
        # Web UI assets
        ('templates',           'templates'),
        ('static',              'static'),
        # LibreOffice – downloaded and staged by build_mac.sh
        # The whole directory is included; soffice lives at
        #   {MEIPASS}/LibreOffice/Contents/MacOS/soffice
        ('bundled_libreoffice', 'LibreOffice'),
    ],
    hiddenimports=[
        # lxml C extensions are not always auto-detected
        'lxml._elementpath',
        'lxml.etree',
        # pywebview macOS backend
        'webview.platforms.cocoa',
        'webview.platforms.macosx',
        # pyobjc frameworks used by pywebview on macOS
        'objc',
        'Foundation',
        'AppKit',
        'WebKit',
        # Flask internals occasionally missed
        'flask',
        'flask.json.provider',
        'jinja2.ext',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        # Not needed at runtime in the macOS app
        'tkinter',
        'matplotlib',
        'numpy',
        'pandas',
        'PIL',
        'pytest',
        # Server-only — not used in the native macOS app
        'gunicorn',
    ],
    win_no_prefer_redirects=False,
    win_private_assemblies=False,
    cipher=block_cipher,
    noarchive=False,
)

pyz = PYZ(a.pure, a.zipped_data, cipher=block_cipher)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name='PrintScript',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,         # UPX can break codesigning; leave off
    console=False,     # No terminal window
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,  # None = match current machine (arm64 or x86_64)
    codesign_identity=None,
    entitlements_file=None,
)

coll = COLLECT(
    exe,
    a.binaries,
    a.zipfiles,
    a.datas,
    strip=False,
    upx=False,
    upx_exclude=[],
    name='PrintScript',
)

app = BUNDLE(
    coll,
    name='PrintScript.app',
    icon=None,             # Vervang met 'icon.icns' als je een eigen icoon hebt
    bundle_identifier='nl.printscript.app',
    info_plist={
        'CFBundleName':             'PrintScript',
        'CFBundleDisplayName':      'PrintScript',
        'CFBundleShortVersionString': '1.0.0',
        'CFBundleVersion':          '1',
        'NSHighResolutionCapable':  True,
        # Laat de app gewoon in het Dock verschijnen (geen menu-bar-only app)
        'LSUIElement':              False,
        # Toestemming voor bestandstoegang (drag-and-drop uploads)
        'NSDocumentsFolderUsageDescription': 'PrintScript leest Word-documenten.',
        'NSDesktopFolderUsageDescription':   'PrintScript leest Word-documenten.',
    },
)
