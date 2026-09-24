# -*- coding: utf-8 -*-
from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor

OUT = r"C:\Users\Rysha\OneDrive - ormoc.sti.ph\Desktop\Scope and Limitations SECTION ONLY.docx"

CONTENT = [
    ("h", "Scope and Limitations"),
    ("b", "This capstone project aims to enhance On-the-Job Training at STI College Ormoc by developing a more secure, efficient, and user-friendly Digital Practicum and Internship Deployment and Monitoring System. The study is generally useful in strengthening record accuracy, improving coordination, and simplifying the overall management of practicum deployment. The main beneficiaries of the system include the OJT coordinators and super administrators of STI College Ormoc as organizers, students in the BSIT, BSTM, and BSHM programs as interns, and verified industry partners as host companies."),
    ("b", "The project was conducted during Academic Year 2025–2026, with development from January 2026 through final submission on May 15, 2026. The system supports three academic programs—BSIT, BSTM, and BSHM—with course-specific hour requirements of 486 hours for BSIT and 600 hours for BSTM and BSHM. Testing and evaluation are limited to the intended users and workflows of STI College Ormoc’s OJT program. The backend is built using PHP and MySQL with a REST API connecting the mobile client, deployed on XAMPP for local development and on practicom.online for production use. External integrations within scope include Microsoft Office 365 OAuth for student authentication, Face++ for biometric attendance, Semaphore for SMS verification, and Maileroo for partner onboarding email. The system also includes the modules:"),
    ("g", "Super Administrator (Web Panel):"),
    ("m", "Login Module", "contains signing in to the administrative web panel and restricting access to authorized super administrators."),
    ("m", "Dashboard", "contains institution-wide summaries of applications, deployments, attendance, documents, and program activity."),
    ("m", "User Management", "contains the adding, updating, and deactivating of coordinator and administrator accounts."),
    ("m", "Partner Management", "contains the review, approval, and rejection of industry partner registrations and verification documents across programs."),
    ("m", "Internship Posting Oversight", "contains viewing and overseeing internship postings published for BSIT, BSTM, and BSHM."),
    ("m", "Application and Deployment Oversight", "contains viewing student applications and deployed interns across programs."),
    ("m", "Reports and Analytics", "contains dashboards and exportable summaries of applications, deployments, attendance, and rendered hours."),
    ("g", "OJT Coordinator (Web Panel):"),
    ("m", "Login Module", "contains signing in to the administrative web panel for the coordinator’s assigned program."),
    ("m", "Dashboard", "contains program-level summaries of applications, deployments, attendance, and documents."),
    ("m", "Partner Management", "contains the review, approval, and rejection of industry partners relevant to the assigned course."),
    ("m", "Internship Posting Management", "contains adding, updating, and overseeing internship postings for the assigned program."),
    ("m", "Application Processing", "contains moving student applications through pending, under review, for interview, for deployment, and deployed."),
    ("m", "Document Management", "contains tracking Memoranda of Agreement, endorsement letters, and student-submitted files required for deployment."),
    ("m", "Attendance and Hours Monitoring", "contains reviewing attendance logs and comparing accumulated hours with program hour requirements."),
    ("m", "Announcements and Calendar", "contains posting announcements and calendar events for students and partners."),
    ("m", "Reports", "contains program reports on applications, deployments, attendance, and hours."),
    ("g", "OJT Coordinator (Mobile Application):"),
    ("m", "Coordinator Login", "contains signing in on mobile for field monitoring."),
    ("m", "Attendance Monitor", "contains viewing deployed students and inspecting attendance history while away from the office."),
    ("m", "Student Progress View", "contains selected student reports needed for on-site coordination."),
    ("g", "Student (Mobile Application):"),
    ("m", "Office 365 Login", "contains signing in using the institutional Microsoft Office 365 account issued by STI College Ormoc."),
    ("m", "Profile Management", "contains completing the student profile and uploading required documents."),
    ("m", "Internship Browse and Details", "contains viewing course-matched internship postings and posting details."),
    ("m", "Application Module", "contains submitting an application with résumé and supporting files and viewing application status."),
    ("m", "Attendance Module", "contains clock-in and clock-out, optional facial recognition through Face++, and viewing attendance logs and hour summaries."),
    ("m", "Excuse Submission", "contains submitting excuse requests for missed or incomplete duty hours."),
    ("m", "Notifications", "contains receiving in-app notices on application status, announcements, and related updates."),
    ("g", "Industry Partner (Web Portal):"),
    ("m", "Login and Password Change", "contains signing in to the company portal and completing the required initial password change."),
    ("m", "Verification Documents", "contains uploading company verification files for school approval."),
    ("m", "Internship Postings", "contains adding and managing placement opportunities after the company is approved."),
    ("m", "Applicant Review", "contains viewing and reviewing student applications."),
    ("m", "Intern Management", "contains viewing deployed interns assigned to the company."),
    ("m", "Time Log Validation", "contains reviewing and validating intern attendance logs."),
    ("m", "Announcements and Calendar", "contains viewing or posting announcements and calendar items as implemented."),
    ("g", "Public Users (Landing Page):"),
    ("m", "Landing Page", "contains browsing active internship postings and featured industry partners prior to login."),
    ("h2", "Limitations"),
    ("lim", "Platform coverage", "The system does not include a dedicated mobile application for industry partners; host companies access the platform exclusively through the web portal, which may limit convenience for partners who prefer mobile-based supervision."),
    ("lim", "Institutional dependency", "Student authentication relies on Microsoft Office 365 accounts issued by STI College Ormoc. Users without valid institutional credentials cannot access the mobile application, restricting use to enrolled students and authorized personnel."),
    ("lim", "Internet connectivity requirement", "PRACTICOM requires a stable internet connection for all core functions, including attendance logging, face verification, and API communication. Offline mode is not supported."),
    ("lim", "Third-party service dependency", "Biometric attendance verification depends on the Face++ API, and partner onboarding relies on Semaphore and Maileroo. Service outages, API changes, or connectivity issues with these providers may affect system functionality."),
    ("lim", "Password hashing algorithm", "The current implementation stores web-based admin and company account passwords using MD5, a hashing algorithm that is now considered cryptographically broken and insecure for password storage: it is fast to compute, has no built-in salting, and is vulnerable to collision attacks and rainbow-table lookups. This is a known security limitation of the present implementation and not a recommended practice. Mobile accounts are not affected, as they use token-based (Bearer) authentication rather than stored password hashes. Migrating web account password storage to a salted, adaptive hashing algorithm such as bcrypt or Argon2, together with HTTPS enforcement and CSRF protection, is identified as a required step before any production or public deployment of the system beyond the scope of this study."),
]


def tnr(run, size=12, bold=False):
    run.bold = bold
    run.font.name = "Times New Roman"
    run.font.size = Pt(size)
    run.font.color.rgb = RGBColor(0, 0, 0)
    rPr = run._element.get_or_add_rPr()
    rFonts = rPr.find(qn("w:rFonts"))
    if rFonts is None:
        rFonts = OxmlElement("w:rFonts")
        rPr.insert(0, rFonts)
    rFonts.set(qn("w:ascii"), "Times New Roman")
    rFonts.set(qn("w:hAnsi"), "Times New Roman")
    rFonts.set(qn("w:cs"), "Times New Roman")
    rFonts.set(qn("w:eastAsia"), "Times New Roman")


def main():
    doc = Document()
    sec = doc.sections[0]
    sec.left_margin = Inches(1.5)
    sec.right_margin = Inches(1.0)
    sec.top_margin = Inches(1.0)
    sec.bottom_margin = Inches(1.0)
    style = doc.styles["Normal"]
    style.font.name = "Times New Roman"
    style.font.size = Pt(12)

    for kind, *rest in CONTENT:
        p = doc.add_paragraph()
        p.paragraph_format.line_spacing = 1.5
        p.paragraph_format.space_after = Pt(6)
        if kind == "h":
            p.alignment = WD_ALIGN_PARAGRAPH.LEFT
            r = p.add_run(rest[0])
            tnr(r, 12, True)
        elif kind == "h2":
            p.alignment = WD_ALIGN_PARAGRAPH.LEFT
            r = p.add_run(rest[0])
            tnr(r, 12, True)
        elif kind == "b":
            p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
            r = p.add_run(rest[0])
            tnr(r, 12, False)
        elif kind == "g":
            p.alignment = WD_ALIGN_PARAGRAPH.LEFT
            r = p.add_run(rest[0])
            tnr(r, 12, True)
        elif kind == "m":
            p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
            r1 = p.add_run(rest[0] + " – ")
            tnr(r1, 12, True)
            r2 = p.add_run(rest[1])
            tnr(r2, 12, False)
        elif kind == "lim":
            p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
            r1 = p.add_run("• " + rest[0] + " — ")
            tnr(r1, 12, True)
            r2 = p.add_run(rest[1])
            tnr(r2, 12, False)

    doc.save(OUT)
    print("Wrote", OUT)


if __name__ == "__main__":
    main()
