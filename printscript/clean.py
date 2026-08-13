"""
Document cleaning: comments and highlighting.

Both operations run over every content part of the package (body, headers,
footers, foot/endnotes) and over styles.xml and numbering.xml, so a highlight
that lives in a style definition disappears just like a directly applied one.

Removing images after page 1 is *not* done here: which page an image lands on
only becomes knowable once the document is laid out, so that step lives in
``pdf.py`` where the real page boxes are available.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import List

from .ns import NS, qn
from .package import Package

# Relationship kinds whose parts contain renderable body content.
_CONTENT_KINDS = ('header', 'footer', 'footnotes', 'endnotes')

# Relationship kinds that carry comment data; these parts are dropped wholesale.
_COMMENT_KINDS = (
    'comments',
    'commentsExtended',
    'commentsIds',
    'commentsExtensible',
    'people',
)

_COMMENT_MARKERS = (
    'w:commentRangeStart',
    'w:commentRangeEnd',
    'w:commentReference',
    'w:annotationRef',
)


@dataclass
class CleanReport:
    comment_markers_removed: int = 0
    comment_parts_removed: int = 0
    highlights_removed: int = 0
    shadings_removed: int = 0

    @property
    def total_highlighting_removed(self) -> int:
        return self.highlights_removed + self.shadings_removed


def content_parts(package: Package) -> List[str]:
    """Main document plus every header/footer/footnote part it references."""
    main = package.main_part_name()
    parts = [main] if main else []
    if main:
        for rel in package.rels(main).values():
            if rel.kind in _CONTENT_KINDS and not rel.external:
                name = package.related_part_name(main, rel.id)
                if name and package.has_part(name) and name not in parts:
                    parts.append(name)
    return parts


def _styling_parts(package: Package) -> List[str]:
    parts = content_parts(package)
    main = package.main_part_name()
    if main:
        for rel in package.rels(main).values():
            if rel.kind in ('styles', 'numbering') and not rel.external:
                name = package.related_part_name(main, rel.id)
                if name and package.has_part(name) and name not in parts:
                    parts.append(name)
    return parts


# ── 1. Comments ──────────────────────────────────────────────────────────────

def remove_comments(package: Package, report: CleanReport | None = None) -> CleanReport:
    """
    Strip every trace of comments: the in-text anchors, the runs that only
    exist to carry a comment reference, and the comment parts themselves.
    """
    report = report or CleanReport()

    for part in content_parts(package):
        root = package.tree(part)
        if root is None:
            continue

        for marker in _COMMENT_MARKERS:
            for node in root.iter(qn(marker)):
                parent = node.getparent()
                if parent is not None:
                    parent.remove(node)
                    report.comment_markers_removed += 1

        # A run left with nothing but formatting is invisible but can still
        # produce a stray empty span; drop the ones we just emptied.
        for run in list(root.iter(qn('w:r'))):
            if len(run) == 0 or all(child.tag == qn('w:rPr') for child in run):
                parent = run.getparent()
                if parent is not None:
                    parent.remove(run)

    main = package.main_part_name()
    if main:
        for rel in list(package.rels(main).values()):
            if rel.kind in _COMMENT_KINDS and not rel.external:
                name = package.related_part_name(main, rel.id)
                if name and package.has_part(name):
                    package.drop_part(name)
                    report.comment_parts_removed += 1

    return report


# ── 2. Highlighting ──────────────────────────────────────────────────────────

def remove_highlighting(package: Package, report: CleanReport | None = None) -> CleanReport:
    """
    Remove every highlight and every character/paragraph shading.

    Word's highlighter pen writes ``w:highlight``; Google Docs' "highlight
    colour" writes ``w:shd`` inside the run properties.  Both go.  ``w:color``
    (the text colour) and table-cell shading are deliberately left alone —
    coloured text stays coloured and table designs stay intact.
    """
    report = report or CleanReport()

    for part in _styling_parts(package):
        root = package.tree(part)
        if root is None:
            continue

        for node in list(root.iter(qn('w:highlight'))):
            parent = node.getparent()
            if parent is not None:
                parent.remove(node)
                report.highlights_removed += 1

        for node in list(root.iter(qn('w:shd'))):
            parent = node.getparent()
            if parent is not None and parent.tag in (qn('w:rPr'), qn('w:pPr')):
                parent.remove(node)
                report.shadings_removed += 1

    return report


# ── Combined entry point ─────────────────────────────────────────────────────

def clean(package: Package) -> CleanReport:
    """Apply every document-level cleaning step, in order."""
    report = CleanReport()
    remove_comments(package, report)
    remove_highlighting(package, report)
    return report


def count_comment_markers(package: Package) -> int:
    """Diagnostic helper used by the tests."""
    total = 0
    for part in content_parts(package):
        root = package.tree(part)
        if root is None:
            continue
        for marker in _COMMENT_MARKERS:
            total += len(list(root.iter(qn(marker))))
    return total


def count_highlighting(package: Package) -> int:
    """Diagnostic helper used by the tests."""
    total = 0
    for part in _styling_parts(package):
        root = package.tree(part)
        if root is None:
            continue
        total += len(list(root.iter(qn('w:highlight'))))
        for node in root.iter(qn('w:shd')):
            parent = node.getparent()
            if parent is not None and parent.tag in (qn('w:rPr'), qn('w:pPr')):
                total += 1
    return total


__all__ = [
    'CleanReport',
    'clean',
    'content_parts',
    'count_comment_markers',
    'count_highlighting',
    'remove_comments',
    'remove_highlighting',
    'NS',
]
