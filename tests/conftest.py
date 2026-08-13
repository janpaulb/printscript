"""Shared test helpers: everything is asserted against the finished PDF."""

from __future__ import annotations

import io
import os
import re
import sys
from typing import List

import pytest
from pypdf import PdfReader

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import fixtures  # noqa: E402  (added to the path just above)

# WeasyPrint shares one resource dictionary between pages, so `page.images`
# lists images the page never draws.  The content stream is the truth: an image
# is on a page only if that page invokes it with the `Do` operator.
_DRAW_OPERATOR = re.compile(rb'/([A-Za-z0-9#_]+)\s+Do\b')


def pdf_pages(pdf: bytes) -> List:
    return PdfReader(io.BytesIO(pdf)).pages


def page_texts(pdf: bytes) -> List[str]:
    return [page.extract_text() for page in pdf_pages(pdf)]


def all_text(pdf: bytes) -> str:
    return '\n'.join(page_texts(pdf))


def images_per_page(pdf: bytes) -> List[int]:
    counts = []
    for page in pdf_pages(pdf):
        contents = page.get_contents()
        data = contents.get_data() if contents is not None else b''
        counts.append(len(_DRAW_OPERATOR.findall(data)))
    return counts


@pytest.fixture
def builder():
    return fixtures.DocxBuilder()


@pytest.fixture
def app():
    from app import create_app
    application = create_app()
    application.config.update(TESTING=True)
    return application


@pytest.fixture
def client(app):
    return app.test_client()
