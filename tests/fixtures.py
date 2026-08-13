"""
Hand-built .docx fixtures.

The tests need documents with things python-docx cannot easily produce —
comments, shading, section breaks, PAGE fields — so the fixtures assemble the
OOXML package directly.  That also means the test suite has no dependency on
whatever library happens to have written a document.
"""

from __future__ import annotations

import io
import struct
import zipfile
import zlib
from typing import Dict, Optional

DECL = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'

NS_DECL = (
    'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
    'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
    'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
    'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
    'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" '
    'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
    'xmlns:v="urn:schemas-microsoft-com:vml"'
)

_RELS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships'
_OFFICE_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
_WORD_TYPE = ('application/vnd.openxmlformats-officedocument.wordprocessingml'
              '.document.main+xml')

_PART_TYPES = {
    'styles': 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
    'numbering': 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
    'settings': 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml',
    'comments': 'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml',
    'header': 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml',
    'footer': 'application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml',
    'footnotes': 'application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml',
}

DEFAULT_SECTION = (
    '<w:sectPr>'
    '<w:pgSz w:w="11906" w:h="16838"/>'
    '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" '
    'w:header="708" w:footer="708" w:gutter="0"/>'
    '</w:sectPr>'
)

DEFAULT_STYLES = (
    '<w:styles ' + NS_DECL + '>'
    '<w:docDefaults><w:rPrDefault><w:rPr>'
    '<w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="22"/>'
    '</w:rPr></w:rPrDefault></w:docDefaults>'
    '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
    '<w:name w:val="Normal"/></w:style>'
    '<w:style w:type="paragraph" w:styleId="Heading1">'
    '<w:name w:val="heading 1"/><w:basedOn w:val="Normal"/>'
    '<w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr>'
    '<w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
    '</w:styles>'
)


class DocxBuilder:
    """Assembles a valid .docx package part by part."""

    MAIN = 'word/document.xml'

    def __init__(self):
        self._parts: Dict[str, bytes] = {}
        # owner part -> [(rId, type suffix, target relative to the owner)]
        self._rels: Dict[str, list] = {}
        self._overrides: Dict[str, str] = {}
        self._next_rel = 1
        self.add_part('styles', 'word/styles.xml', DEFAULT_STYLES)

    # ── Building blocks ──────────────────────────────────────────────────────

    def _rel_id(self) -> str:
        self._next_rel += 1
        return 'rId%d' % self._next_rel

    def _add_rel(self, owner: str, rel_id: str, kind: str, target: str) -> None:
        self._rels.setdefault(owner, []).append((rel_id, kind, target))

    def add_part(self, kind: str, path: str, xml: str, rel_id: Optional[str] = None,
                 owner: Optional[str] = None) -> str:
        rel_id = rel_id or self._rel_id()
        self._parts[path] = (DECL + xml).encode('utf-8')
        self._overrides['/' + path] = _PART_TYPES[kind]
        self._add_rel(owner or self.MAIN, rel_id, kind, path.split('/', 1)[1])
        return rel_id

    def add_header(self, xml: str, rel_id: Optional[str] = None) -> str:
        index = sum(1 for name in self._parts if name.startswith('word/header')) + 1
        return self.add_part('header', 'word/header%d.xml' % index,
                             '<w:hdr ' + NS_DECL + '>' + xml + '</w:hdr>', rel_id)

    def add_footer(self, xml: str, rel_id: Optional[str] = None) -> str:
        index = sum(1 for name in self._parts if name.startswith('word/footer')) + 1
        return self.add_part('footer', 'word/footer%d.xml' % index,
                             '<w:ftr ' + NS_DECL + '>' + xml + '</w:ftr>', rel_id)

    def add_comments(self, xml: str) -> str:
        return self.add_part('comments', 'word/comments.xml',
                             '<w:comments ' + NS_DECL + '>' + xml + '</w:comments>')

    def add_numbering(self, xml: str) -> str:
        return self.add_part('numbering', 'word/numbering.xml',
                             '<w:numbering ' + NS_DECL + '>' + xml + '</w:numbering>')

    def add_settings(self, xml: str = '') -> str:
        return self.add_part('settings', 'word/settings.xml',
                             '<w:settings ' + NS_DECL + '>' + xml + '</w:settings>')

    def add_styles(self, xml: str) -> None:
        self._parts['word/styles.xml'] = (DECL + xml).encode('utf-8')

    def add_image(self, data: bytes, extension: str = 'png',
                  rel_id: Optional[str] = None, owner: Optional[str] = None) -> str:
        """Add a media part.  *owner* is the part that references it."""
        rel_id = rel_id or self._rel_id()
        index = sum(1 for name in self._parts if name.startswith('word/media/')) + 1
        path = 'word/media/image%d.%s' % (index, extension)
        self._parts[path] = data
        self._add_rel(owner or self.MAIN, rel_id, 'image', path.split('/', 1)[1])
        return rel_id

    # ── Output ───────────────────────────────────────────────────────────────

    def build(self, body: str) -> bytes:
        document = (DECL + '<w:document ' + NS_DECL + '><w:body>' + body +
                    '</w:body></w:document>').encode('utf-8')

        content_types = [DECL, '<Types xmlns="http://schemas.openxmlformats.org/'
                         'package/2006/content-types">',
                         '<Default Extension="rels" ContentType="application/'
                         'vnd.openxmlformats-package.relationships+xml"/>',
                         '<Default Extension="xml" ContentType="application/xml"/>',
                         '<Default Extension="png" ContentType="image/png"/>',
                         '<Default Extension="jpeg" ContentType="image/jpeg"/>',
                         '<Default Extension="emf" ContentType="image/emf"/>',
                         '<Override PartName="/word/document.xml" ContentType="%s"/>'
                         % _WORD_TYPE]
        for part_name, content_type in self._overrides.items():
            content_types.append('<Override PartName="%s" ContentType="%s"/>'
                                 % (part_name, content_type))
        content_types.append('</Types>')

        package_rels = (
            DECL + '<Relationships xmlns="%s">' % _RELS_NS +
            '<Relationship Id="rId1" Type="%s/officeDocument" Target="word/document.xml"/>'
            % _OFFICE_REL + '</Relationships>')

        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as zf:
            zf.writestr('[Content_Types].xml', ''.join(content_types))
            zf.writestr('_rels/.rels', package_rels)
            zf.writestr('word/document.xml', document)
            for owner, relationships in self._rels.items():
                directory, _, base = owner.rpartition('/')
                lines = [DECL, '<Relationships xmlns="%s">' % _RELS_NS]
                for rel_id, kind, target in relationships:
                    lines.append('<Relationship Id="%s" Type="%s/%s" Target="%s"/>'
                                 % (rel_id, _OFFICE_REL, kind, target))
                lines.append('</Relationships>')
                zf.writestr('%s/_rels/%s.rels' % (directory, base), ''.join(lines))
            for path, data in self._parts.items():
                zf.writestr(path, data)
        return buffer.getvalue()


# ── XML snippets ─────────────────────────────────────────────────────────────


def paragraph(text: str = '', *, style: Optional[str] = None,
              run_properties: str = '', properties: str = '',
              extra: str = '') -> str:
    ppr = ''
    if style or properties:
        ppr = '<w:pPr>%s%s</w:pPr>' % (
            '<w:pStyle w:val="%s"/>' % style if style else '', properties)
    run = ''
    if text:
        run = ('<w:r>%s<w:t xml:space="preserve">%s</w:t></w:r>'
               % ('<w:rPr>%s</w:rPr>' % run_properties if run_properties else '', text))
    return '<w:p>%s%s%s</w:p>' % (ppr, run, extra)


def page_break_paragraph() -> str:
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'


def image_run(rel_id: str, width_emu: int = 1905000, height_emu: int = 1905000,
              name: str = 'Afbeelding') -> str:
    return (
        '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        '<wp:extent cx="%d" cy="%d"/>'
        '<wp:docPr id="1" name="%s"/>'
        '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/'
        'drawingml/2006/picture"><pic:pic>'
        '<pic:nvPicPr><pic:cNvPr id="0" name="%s"/><pic:cNvPicPr/></pic:nvPicPr>'
        '<pic:blipFill><a:blip r:embed="%s"/><a:stretch><a:fillRect/></a:stretch>'
        '</pic:blipFill>'
        '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%d" cy="%d"/></a:xfrm>'
        '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        '</pic:pic></a:graphicData></a:graphic>'
        '</wp:inline></w:drawing></w:r>'
        % (width_emu, height_emu, name, name, rel_id, width_emu, height_emu))


def commented_paragraph(text: str, comment_text: str, comment_id: str = '1') -> str:
    return (
        '<w:p>'
        '<w:commentRangeStart w:id="%s"/>'
        '<w:r><w:t xml:space="preserve">%s</w:t></w:r>'
        '<w:commentRangeEnd w:id="%s"/>'
        '<w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr>'
        '<w:commentReference w:id="%s"/></w:r>'
        '</w:p>' % (comment_id, text, comment_id, comment_id))


def comments_part(comment_text: str, comment_id: str = '1') -> str:
    return ('<w:comment w:id="%s" w:author="Redacteur" w:initials="R">'
            '<w:p><w:r><w:t>%s</w:t></w:r></w:p></w:comment>'
            % (comment_id, comment_text))


def page_field_footer(prefix: str = 'Pagina ') -> str:
    return (
        '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
        '<w:r><w:t xml:space="preserve">%s</w:t></w:r>'
        '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
        '<w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
        '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
        '<w:r><w:t>7</w:t></w:r>'
        '<w:r><w:fldChar w:fldCharType="end"/></w:r>'
        '</w:p>' % prefix)


def section_with_footer(footer_rel: str, header_rel: Optional[str] = None,
                        title_page: bool = False, extra: str = '') -> str:
    references = '<w:footerReference w:type="default" r:id="%s"/>' % footer_rel
    if header_rel:
        references = ('<w:headerReference w:type="default" r:id="%s"/>' % header_rel
                      + references)
    return (
        '<w:sectPr>%s%s'
        '<w:pgSz w:w="11906" w:h="16838"/>'
        '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" '
        'w:header="708" w:footer="708"/>%s'
        '</w:sectPr>'
        % (references, '<w:titlePg/>' if title_page else '', extra))


# ── A real, tiny PNG ─────────────────────────────────────────────────────────


def png_bytes(width: int = 40, height: int = 40,
              colour: tuple = (220, 40, 40)) -> bytes:
    """A minimal, valid RGB PNG — no Pillow needed."""
    raw = b''.join(b'\x00' + bytes(colour) * width for _ in range(height))

    def chunk(tag: bytes, payload: bytes) -> bytes:
        return (struct.pack('>I', len(payload)) + tag + payload +
                struct.pack('>I', zlib.crc32(tag + payload) & 0xFFFFFFFF))

    header = struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', header) +
            chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))
