# -*- coding: utf-8 -*-
from docx import Document
from docx.oxml.ns import qn

path = r"C:\Users\Rysha\OneDrive - ormoc.sti.ph\Desktop\PRACTICOM (2).docx"
doc = Document(path)
for i, p in enumerate(doc.paragraphs[157:174], start=157):
    print("=" * 60)
    print(i, p.style.name)
    print(p.text)
    xml = p._p.xml
    flags = []
    if "commentRange" in xml or "commentReference" in xml:
        flags.append("COMMENT")
    if "w:ins" in xml or "w:del" in xml:
        flags.append("TRACK")
    if flags:
        print("FLAGS", flags)
