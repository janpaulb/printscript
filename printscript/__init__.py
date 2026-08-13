"""
PrintScript — Google Docs (or Word) in, print-ready PDF out.

The conversion always applies the same four rules:

  1. every comment is removed;
  2. every highlight and text shading is removed, text colour is kept;
  3. every image after page 1 is removed, page 1 is left untouched;
  4. page numbering in the footer is preserved (and added when missing).

Public entry points live in :mod:`printscript.pipeline`.
"""

from .pipeline import (ConversionOptions, ConversionResult, convert_docx,
                       convert_google_doc)

__version__ = '2.0.0'

__all__ = [
    'ConversionOptions',
    'ConversionResult',
    'convert_docx',
    'convert_google_doc',
    '__version__',
]
