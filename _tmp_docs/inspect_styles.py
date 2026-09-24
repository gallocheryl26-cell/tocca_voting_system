# -*- coding: utf-8 -*-
from pathlib import Path
from zipfile import ZipFile
import xml.etree.ElementTree as ET
from docx import Document
from docx.oxml.ns import qn

REF = Path(r"c:\Users\Rysha\OneDrive - ormoc.sti.ph\Documents\TOCCA Capstone Final Document Revision.docx")
W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"

# styles.xml details for Heading 1/2/3, Title, Normal, Body of Research, Abstract
with ZipFile(REF) as z:
    styles = ET.fromstring(z.read("word/styles.xml"))
    want = {
        "Normal", "Heading 1", "Heading 2", "Heading 3", "Title", "Abstract",
        "Body of Research", "List Paragraph", "toc 1", "toc 2", "Appendix",
        "Proposal 1", "References",
    }
    print("=== STYLE XML ===")
    for st in styles.findall(f"{W}style"):
        name_el = st.find(f"{W}name")
        name = name_el.get(f"{W}val") if name_el is not None else "?"
        if name not in want and not name.startswith("Heading") and name not in ("Body of Research", "Proposal 1"):
            continue
        style_id = st.get(f"{W}styleId")
        based = st.find(f"{W}basedOn")
        based_v = based.get(f"{W}val") if based is not None else None
        rPr = st.find(f"{W}rPr")
        pPr = st.find(f"{W}pPr")
        def dump_rpr(rPr):
            if rPr is None:
                return ""
            bits = []
            rf = rPr.find(f"{W}rFonts")
            if rf is not None:
                bits.append(f"fonts ascii={rf.get(f'{W}ascii')} hAnsi={rf.get(f'{W}hAnsi')} cs={rf.get(f'{W}cs')}")
            sz = rPr.find(f"{W}sz")
            if sz is not None:
                bits.append(f"sz={int(sz.get(f'{W}val'))/2}pt")
            szcs = rPr.find(f"{W}szCs")
            if szcs is not None:
                bits.append(f"szCs={int(szcs.get(f'{W}val'))/2}pt")
            if rPr.find(f"{W}b") is not None:
                bits.append(f"b={rPr.find(f'{W}b').get(f'{W}val')}")
            if rPr.find(f"{W}i") is not None:
                bits.append("italic")
            jc = None
            return ", ".join(bits)
        def dump_ppr(pPr):
            if pPr is None:
                return ""
            bits = []
            jc = pPr.find(f"{W}jc")
            if jc is not None:
                bits.append(f"jc={jc.get(f'{W}val')}")
            sp = pPr.find(f"{W}spacing")
            if sp is not None:
                bits.append("spacing " + " ".join(f"{k.split('}')[-1]}={v}" for k,v in sp.attrib.items()))
            ind = pPr.find(f"{W}ind")
            if ind is not None:
                bits.append("ind " + " ".join(f"{k.split('}')[-1]}={v}" for k,v in ind.attrib.items()))
            outline = pPr.find(f"{W}outlineLvl")
            if outline is not None:
                bits.append(f"outline={outline.get(f'{W}val')}")
            pb = pPr.find(f"{W}pageBreakBefore")
            if pb is not None:
                bits.append("pageBreakBefore")
            return ", ".join(bits)
        print(f"\n[{name}] id={style_id} basedOn={based_v}")
        print("  pPr:", dump_ppr(pPr))
        print("  rPr:", dump_rpr(rPr))

    # first 8 sectPr from document
    print("\n=== FIRST 10 sectPr ===")
    xml = z.read("word/document.xml")
    # too large to parse fully maybe - try iterparse
    count = 0
    for event, elem in ET.iterparse(z.open("word/document.xml")):
        if elem.tag == f"{W}sectPr":
            pgSz = elem.find(f"{W}pgSz")
            pgMar = elem.find(f"{W}pgMar")
            pgNum = elem.find(f"{W}pgNumType")
            titlePg = elem.find(f"{W}titlePg")
            typ = elem.find(f"{W}type")
            print(f"sect {count}: type={typ.get(f'{W}val') if typ is not None else None} "
                  f"pgSz={pgSz.attrib if pgSz is not None else None} "
                  f"pgMar={pgMar.attrib if pgMar is not None else None} "
                  f"pgNum={pgNum.attrib if pgNum is not None else None} "
                  f"titlePg={titlePg is not None}")
            count += 1
            if count >= 10:
                break
            elem.clear()

# inspect a few heading paragraphs for effective font via xpath
doc = Document(str(REF))
print("\n=== EFFECTIVE RUN FONTS ON KEY PARAS ===")
keys = ["APPROVAL SHEET", "Acknowledgements", "Abstract", "Table of Contents", "Introduction",
        "Project Context", "Purpose and Description", "Objectives", "Scope and Limitations",
        "METHODOLOGY", "Technical Background", "RESULTS AND DISCUSSION", "CONCLUSION", "References"]
for p in doc.paragraphs:
    t = p.text.strip()
    if t in keys or t.startswith("ENDORSEMENT"):
        r = p.runs[0] if p.runs else None
        print(f"style={p.style.name!r} align={p.alignment} text={t[:60]!r}")
        if r is not None:
            print(f"  run font={r.font.name} size={r.font.size} bold={r.bold} italic={r.italic}")
            rPr = r._element.find(qn("w:rPr"))
            if rPr is not None:
                print("  rPr xml:", ET.tostring(rPr, encoding="unicode")[:400])
        pPr = p._element.find(qn("w:pPr"))
        if pPr is not None:
            print("  pPr xml:", ET.tostring(pPr, encoding="unicode")[:500])
        print()
