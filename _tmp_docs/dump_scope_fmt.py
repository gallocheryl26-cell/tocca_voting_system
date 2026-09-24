# -*- coding: utf-8 -*-
from docx import Document
from docx.oxml.ns import qn
from docx.enum.text import WD_ALIGN_PARAGRAPH

path = r"C:\Users\Rysha\OneDrive - ormoc.sti.ph\Desktop\PRACTICOM (2).docx"
doc = Document(path)

def dump(i):
    p = doc.paragraphs[i]
    pf = p.paragraph_format
    print(f"\n--- {i} ---")
    print("style", p.style.name, "align", p.alignment)
    print("left", pf.left_indent, "first", pf.first_line_indent, "hanging", None)
    print("sb", pf.space_before, "sa", pf.space_after, "ls", pf.line_spacing)
    for r in p.runs[:4]:
        print(" run font", r.font.name, "sz", r.font.size, "bold", r.bold, "italic", r.italic, "text", repr(r.text[:50]))
    pPr = p._p.find(qn("w:pPr"))
    if pPr is not None:
        print("pPr", pPr.xml[:500])

for i in [157, 158, 160, 161]:
    dump(i)
