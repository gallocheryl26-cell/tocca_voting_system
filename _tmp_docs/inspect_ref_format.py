# -*- coding: utf-8 -*-
"""Inspect TOCCA reference DOCX styles, sections, and pagination."""
from collections import Counter
from pathlib import Path
from zipfile import ZipFile
import xml.etree.ElementTree as ET

from docx import Document
from docx.oxml.ns import qn
from docx.enum.text import WD_ALIGN_PARAGRAPH

REF = Path(r"c:\Users\Rysha\OneDrive - ormoc.sti.ph\Documents\TOCCA Capstone Final Document Revision.docx")
OUT = Path(r"c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs")
OUT.mkdir(parents=True, exist_ok=True)

doc = Document(str(REF))

# ---- sections / page setup ----
lines = []
lines.append("=== SECTIONS / PAGE SETUP ===")
for i, sec in enumerate(doc.sections):
    lines.append(f"section {i}")
    lines.append(f"  page: {sec.page_width.inches:.3f} x {sec.page_height.inches:.3f} in")
    lines.append(f"  margins LRTB inches: {sec.left_margin.inches:.3f}, {sec.right_margin.inches:.3f}, {sec.top_margin.inches:.3f}, {sec.bottom_margin.inches:.3f}")
    lines.append(f"  header/footer dist: {sec.header_distance.inches:.3f}, {sec.footer_distance.inches:.3f}")
    lines.append(f"  different first page: {sec.different_first_page_header_footer}")
    lines.append(f"  start type: {sec.start_type}")
    lines.append(f"  orientation: {sec.orientation}")
    try:
        lines.append(f"  header text: {[p.text for p in sec.header.paragraphs if p.text.strip()][:5]}")
        lines.append(f"  footer text: {[p.text for p in sec.footer.paragraphs if p.text.strip()][:5]}")
    except Exception as e:
        lines.append(f"  header/footer err: {e}")

# page number XML in footers/headers
ns = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
with ZipFile(REF) as z:
    names = [n for n in z.namelist() if n.startswith("word/") and n.endswith(".xml")]
    lines.append("\n=== PACKAGE XML FILES (word/*) ===")
    for n in names:
        if any(x in n for x in ("header", "footer", "styles", "numbering", "settings", "document", "sect")):
            lines.append(n)

    for n in names:
        if "header" in n or "footer" in n:
            xml = z.read(n).decode("utf-8", errors="ignore")
            has_pg = "w:pgNum" in xml or "PAGE" in xml or "instrText" in xml
            texts = [t.text or "" for t in ET.fromstring(z.read(n)).iter("{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t")]
            lines.append(f"\n--- {n} pgnum={has_pg} ---")
            lines.append("TEXT: " + " | ".join(t.strip() for t in texts if t and t.strip())[:500])
            if "instrText" in xml:
                root = ET.fromstring(z.read(n))
                instrs = [t.text for t in root.iter("{http://schemas.openxmlformats.org/wordprocessingml/2006/main}instrText") if t.text]
                lines.append("INSTR: " + " | ".join(instrs))

# styles
lines.append("\n=== STYLES ===")
for st in doc.styles:
    try:
        name = st.name
        font = getattr(st.font, "name", None)
        size = st.font.size.pt if st.font.size else None
        bold = st.font.bold
        italic = st.font.italic
        pf = getattr(st, "paragraph_format", None)
        align = None
        sp_before = sp_after = ls = None
        if pf is not None:
            align = pf.alignment
            sp_before = pf.space_before.pt if pf.space_before else None
            sp_after = pf.space_after.pt if pf.space_after else None
            ls = pf.line_spacing
        if font or size or name in ("Normal", "Heading 1", "Heading 2", "Heading 3", "Title", "Subtitle", "TOC Heading"):
            if size or name.startswith("Heading") or name in ("Normal", "Title", "Subtitle"):
                lines.append(f"{name!r}: font={font} size={size} bold={bold} italic={italic} align={align} sb={sp_before} sa={sp_after} ls={ls}")
    except Exception:
        pass

# sample first 80 paragraphs with formatting
lines.append("\n=== FIRST 120 PARAGRAPHS (fmt) ===")
align_map = {
    WD_ALIGN_PARAGRAPH.LEFT: "LEFT",
    WD_ALIGN_PARAGRAPH.CENTER: "CENTER",
    WD_ALIGN_PARAGRAPH.RIGHT: "RIGHT",
    WD_ALIGN_PARAGRAPH.JUSTIFY: "JUSTIFY",
    None: "None",
}
count = 0
for i, p in enumerate(doc.paragraphs):
    t = p.text.strip()
    if not t:
        continue
    runs_info = []
    for r in p.runs[:3]:
        sz = r.font.size.pt if r.font.size else None
        fn = r.font.name
        runs_info.append(f"{fn}/{sz}/b={r.font.bold}/i={r.font.italic}")
    style = p.style.name if p.style else None
    al = align_map.get(p.alignment, str(p.alignment))
    preview = t[:90].replace("\n", " ")
    lines.append(f"{i:04d} style={style!r} al={al} runs={runs_info} | {preview}")
    count += 1
    if count >= 120:
        break

# heading-like unique texts
lines.append("\n=== DISTINCT SHORT HEADING CANDIDATES (len<=80) ===")
seen = []
for p in doc.paragraphs:
    t = p.text.strip()
    if not t or len(t) > 80:
        continue
    st = p.style.name if p.style else ""
    if st.startswith("Heading") or t.isupper() or t in (
        "Project Context", "Purpose and Description", "Objectives", "Scope and Limitations",
        "Review of Related Literature/Studies/Systems", "Technical Background", "Requirements Analysis",
        "Requirements Documentation", "Design of Software, System, Product, and/or Processes",
        "Development", "Testing", "Description of Prototype", "Implementation Plan",
        "Implementation Results", "Conclusion", "References", "Acknowledgements", "Abstract",
        "Table of Contents", "APPROVAL SHEET", "Introduction", "METHODOLOGY",
    ):
        key = (st, t)
        if key not in seen:
            seen.append(key)
            al = align_map.get(p.alignment, str(p.alignment))
            sz = None
            fn = None
            bold = None
            if p.runs:
                r = p.runs[0]
                sz = r.font.size.pt if r.font.size else None
                fn = r.font.name
                bold = r.font.bold
            lines.append(f"style={st!r} al={al} font={fn} sz={sz} bold={bold} | {t}")

out = OUT / "ref_format_report.txt"
out.write_text("\n".join(lines), encoding="utf-8")
print("wrote", out, "chars", out.stat().st_size)
print("paragraphs", len(doc.paragraphs), "tables", len(doc.tables), "sections", len(doc.sections))
