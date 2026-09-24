# -*- coding: utf-8 -*-
from docx import Document
from docx.oxml.ns import qn
import xml.etree.ElementTree as ET

path = r"C:\Users\Rysha\OneDrive - ormoc.sti.ph\Desktop\PRACTICOM (2).docx"
doc = Document(path)

print("=== SCOPE REGION ===")
for i, p in enumerate(doc.paragraphs[150:250], start=150):
    t = p.text.replace("\n", " | ")
    if t.strip() or i <= 165:
        print(f"{i:04d} [{p.style.name}] {t[:220]}")
    if p.text.strip() == "Review of Related Literature/Studies/Systems":
        break

# comments
print("\n=== COMMENTS ===")
# w:comment in comments.xml via part
try:
    comments_part = None
    for rel in doc.part.rels.values():
        if "comments" in rel.reltype:
            comments_part = rel.target_part
            print("reltype", rel.reltype)
            xml = comments_part.blob.decode("utf-8", errors="ignore")
            root = ET.fromstring(comments_part.blob)
            W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
            for c in root.findall(f"{W}comment"):
                cid = c.get(f"{W}id")
                author = c.get(f"{W}author")
                texts = [t.text or "" for t in c.iter(f"{W}t")]
                body = "".join(texts)
                print(f"id={cid} author={author} | {body[:400]}")
except Exception as e:
    print("comment err", e)
