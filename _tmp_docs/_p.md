> **PRACTICOM: A Digital Practicum and Internship Deployment Monitoring
> System for STI College Ormoc**
>
> **A Capstone Project Presented to the Faculty of the**
>
> **Information and Communications Technology Program STI College
> Ormoc**
>
> **In Partial Fulfilment**
>
> **of the Requirements for the Degree Bachelor of Science in
> Information Technology**
>
> **Angelo Christian G. Aragon John Lloyd B. Gajol**
>
> **Jude Q. Mojado**
>
> **Jose Andrew Miguel P. Romo Stanley Argy M. Socorin**
>
> **May 15, 2026**

## ENDORSEMENT FORM FOR ORAL DEFENSE

### TITLE OF RESEARCH: PRACTICOM: A Digital Practicum and

> **Internship Deployment and Monitoring System for STI College Ormoc**

**NAME OF PROPONENTS:** Angelo Christian G. Aragon

> John Lloyd B. Gajo Jude Q. Mojado
>
> Jose Andrew Miguel P. Romo Stanley Argy M. Socorin
>
> In Partial Fulfilment of the Requirements
>
> for the degree Bachelor of Science in Information Technology has been
> examined and is recommended for Oral Defense.

## ENDORSED BY:

> **Elizabeth L. Dumaran Capstone Project Adviser**

## APPROVED FOR ORAL DEFENSE:

> **Rona Mira B. Lucañas Capstone Project Coordinator**

## NOTED BY:

> **Rona Mira B. Lucañas Program Head**
>
> **May 15, 2026**

## APPROVAL SHEET

> This capstone project titled **PRACTICOM: A Digital Practicum And
> Internship Monitoring and Deployment System For STI College Ormoc**,
> prepared and submitted by **Angelo Christian G. Aragon, John Lloyd B.
> Gajol**, **Jude Q. Mojado, Jam Andrew Miguel P. Romo**, and **Stanley
> Argy M. Socorin**, in partial fulfillment of the requirements for the
> degree of Bachelor of Science in Information Technology, has been
> examined and is recommended for acceptance and approval.

### Elizabeth L. Dumaran

Capstone Project Adviser

> Accepted and approved by the Capstone Project Review Panel in partial
> fulfillment of the requirements for the degree of Bachelor of Science
> in Technology Information
>
> **Engr. Sheena Joy S. Muyuela Rona Mira B. Lucañas Panel Member Panel
> Member**
>
> **Joseph Nonel Bautista Lead Panelist**
>
> **Noted:**
>
> **Elizabeth L. Dumaran Rona Mira B. Lucañas Capstone Project
> Coordinator Program Head**
>
> **May 15, 2026**

### Abstract

> Title of research**:** PRACTICOM: A Digital Practicum and Internship
> Monitoring and Deployment System for STI College Ormoc
>
> Researchers: **Angelo Christian G. Aragon**
>
> **John Lloyd B. Gajol Jude Q. Mojado**
>
> **Jose Andrew Miguel P. Romo Stanley Argy M. Socorin**
>
> Degree: **Bachelor of Science in Information Technology**
>
> Date of Completion: **July 2, 2026**
>
> Keywords: **Practicum Management System, Internship Deployment,
> Student Monitoring, Company Partner Portal, School Coordinator
> Dashboard, Progress Tracking, Attendance Monitoring, Evaluation
> System, Digital Reports, STI College Ormoc, Student Placement,
> Industry Partnership, Academic Monitoring, Role-Based Access, Web and
> Mobile System**
>
> This capstone project presents the design and development of a
> Localized Internship and Practicum Management System for Higher
> Education Institutions in Ormoc City. The study addresses the existing
> challenges in internship and practicum placement processes, which are
> commonly handled through manual, unstructured, and referral-based
> methods. These traditional practices often result in limited access to
> suitable opportunities, inefficient coordination between students and
> organizations, and increased administrative workload for academic
> institutions.
>
> The primary objective of the project is to create a centralized web
> and mobile-based platform that streamlines the internship and
> practicum placement process. The system is designed to connect
> students, higher education institutions, and partner organizations
> within Ormoc City in a structured
>
> digital environment. It allows students to create profiles, upload
> required documents, search for relevant internship or practicum
> opportunities, and apply based on their course requirements and
> required training hours. Partner organizations can post placement
> opportunities, define qualifications, and manage student applications
> efficiently, while administrators oversee system operations and
> verification processes.
>
> The system incorporates role-based access control to ensure
> appropriate functionality for students, organizations, and
> administrators. It also includes document submission and verification
> features to promote legitimacy, safety, and compliance with academic
> requirements. Basic tracking and reporting functions are integrated to
> assist educational institutions in monitoring student placement status
> and practicum progress.
>
> Overall, the proposed system aims to improve accessibility,
> organization, and efficiency in internship and practicum management
> within Ormoc City. By focusing on localized partnerships and
> curriculum-aligned placements, the project contributes to enhancing
> student industry exposure and supporting higher education institutions
> in effectively managing practicum programs

## TABLE OF CONTENTS

> Page
>
> Title Page i

[Endorsement form for Oral Defense
ii](#endorsement-form-for-oral-defense)

[Abstract iii](#abstract)

Approval Sheet iv

Acknowledgments v

[Table of Contents vi](#table-of-contents)

List of Figures vii

List of Tables viii

List of Notations ix

[Introduction 1](#_TOC_250003)

[Project Context 2](#introduction)

[Purpose and Description 3](#purpose-and-description)

[Objectives 5](#objectives)

> Scope and Limitations
>
> Review of Related Literature/Studies/Systems
>
> 7-8
>
> 9
>
> Methodology 10
>
> Technical Background 11
>
> Requirements Analysis 12
>
> Calendar of Activities 13
>
> Requirements Documentation 14-16
>
> Design of Software, System, Product, and/or Processes Development
>
> Results and Discussion Testing
>
> Description of Prototype Implementation Plan Implementation Results
>
> Conclusion References
>
> Appendices
>
> Relevant Source Code
>
> User's Guide
>
> Personal Technical Vitae

## INTRODUCTION

### Project Context

> PRACTICOM (A Practical Internship Deployment and Monitoring System) is
> an integrated web and mobile platform developed for STI College Ormoc
> to manage On-the-Job Training (OJT) across three academic programs: BS
> Information Technology (BSIT), BS Tourism Management (BSTM), and BS
> Hospitality Management (BSHM). The system addresses the inefficiencies
> of manual OJT coordination---spreadsheet-based tracking, paper
> endorsements, fragmented attendance records, and delayed communication
> among students, host companies, and school administrators.
>
> Internship programs require sustained coordination among multiple
> stakeholders. Students must discover opportunities, submit
> applications, and accumulate required training hours. Industry
> partners must review applicants, supervise interns, and validate
> attendance. OJT coordinators must enforce course-specific
> requirements, approve deployments, generate official documents such as
> endorsement letters and Memoranda of Agreement (MOA), and monitor
> student progress. Without a centralized system, these processes become
> error-prone, time-consuming, and difficult to audit.
>
> PRACTICOM centralizes the full OJT lifecycle into a single platform
> with four access points: a public landing page for internship
> discovery, an administrative web panel for coordinators and super
> administrators, an industry partner web portal for host companies, and
> a cross-platform Flutter mobile application for students and
> coordinators. All components connect to a shared PHP REST API backed
> by a MySQL database (practicom_db), deployed locally through XAMPP and
> in production at practicom.online.
>
> The platform implements a structured application-to-deployment
> pipeline. Students authenticate via Microsoft Office 365 OAuth,
> complete their profiles, browse course-matched internship postings,
> and submit applications with supporting documents. Coordinators manage
> applications through defined stages---pending, under review, for
> interview, for deployment, and deployed---while tracking partner
> approvals and MOA compliance. Upon deployment, students record
> attendance through mobile clock-in and clock-out, optionally verified
> through facial recognition via the Face++ API. Accumulated hours are
> measured against program requirements (486 hours for BSIT; 600 hours
> for BSTM and BSHM), with host companies reviewing attendance logs and
> coordinators monitoring progress through analytics dashboards.
>
> Beyond deployment tracking, PRACTICOM includes document management,
> in-app notifications, announcements, calendar events, student excuse
> submission, project-based completion criteria, and attendance
> reporting. Role-based access control distinguishes super
> administrators from course-scoped OJT coordinators, while industry
> partners manage postings and intern supervision independently through
> their portal.
>
> The technology stack combines PHP and MySQL for server-side logic,
> HTML, CSS, and JavaScript for web interfaces, and Flutter (Dart) for
> the mobile client. External integrations include Azure Active
> Directory for institutional login, Face++ for biometric attendance,
> Semaphore for SMS verification, and Maileroo for partner onboarding
> emails.
>
> PRACTICOM represents a capstone-level, institution-specific solution
> that automates multi-stakeholder OJT workflows, enforces academic
> compliance, and provides real-time monitoring of student internship
> progress. It offers a practical and replicable model for Philippine
> higher education institutions seeking to modernize internship
> management through integrated web and mobile technologies.

### Purpose and Description

> PRACTICOM (A Practical Internship Deployment and Monitoring System)
> was developed for STI College Ormoc to address the inefficiencies of
> manual On-the-Job Training (OJT) coordination across its BS
> Information Technology (BSIT), BS Tourism Management (BSTM), and BS
> Hospitality Management (BSHM) programs. Traditional internship
> management relied on paper-based applications, email correspondence,
> spreadsheet hour tracking, and in-person document
> submission---processes that created bottlenecks for OJT coordinators,
> limited transparency for students, and placed unnecessary
> administrative burden on industry partners. PRACTICOM was built to
> eliminate these inefficiencies by digitizing every stage of the
> internship lifecycle, from partner company onboarding through student
> deployment and completion, while ensuring compliance with
> course-specific training hour requirements and maintaining accurate,
> auditable records for all stakeholders.
>
> The system is a full-stack platform composed of a PHP/MySQL web
> backend, three web-based client interfaces, and a Flutter mobile
> application, deployed locally via XAMPP and in production at
> practicom.online. The public landing page allows students to browse
> active internship postings and featured industry partners. The
> administrative web panel serves OJT coordinators and super
> administrators with modules for partner management, internship posting
> oversight, application review, deployment monitoring, document
> generation, student registry, and attendance analytics. The industry
> partner portal enables host companies to review applicants, manage
> listings, validate attendance records, and communicate with deployed
> interns. Coordinators may also access attendance monitoring and
> student reports through the mobile application while in the field.
>
> The mobile application serves as the primary interface for students.
> Students authenticate through institutional Microsoft Office 365
> accounts via OAuth 2.0, complete their profiles, browse course-matched
> postings, and submit applications with supporting documents.
> Applications follow a defined pipeline---pending, under review, for
> interview, for deployment, and deployed---managed by coordinators who
> schedule interviews, generate endorsement letters, and track Memoranda
> of Agreement with partner companies. During active deployment,
> students log daily attendance through mobile clock-in and clock-out,
> optionally verified through facial recognition via the Face++ API to
> prevent fraudulent time logging. Accumulated hours are measured
> against program requirements---486 hours for BSIT and 600 hours for
> BSTM and BSHM---with support for both hours-based and
> project-task-based completion criteria.
>
> Beyond deployment tracking, PRACTICOM provides in-app notifications,
> announcements, calendar events, student excuse submission, and
> attendance reporting. Role-based access control distinguishes super
> administrators from course-scoped coordinators, ensuring each
> administrator manages only their assigned programs. External
> integrations---including Azure Active Directory, Semaphore for SMS
> verification, and Maileroo for transactional email---support secure
> authentication and partner onboarding. Through these capabilities,
> PRACTICOM serves as the official OJT portal for STI College Ormoc,
> offering a practical and replicable model for digitizing internship
> management in Philippine higher education.

### Objectives

> The general objective of this study is to design, develop, and deploy
> PRACTICOM, an integrated web and mobile platform that digitizes the
> full On-the-Job Training lifecycle at STI College Ormoc. Specifically,
> the study aims to replace manual, fragmented OJT processes with a
> centralized system that connects students, OJT coordinators, and
>
> industry partners through a shared database, REST API, and role-based
> interfaces. The following specific objectives guide the development
> and evaluation of the system:

- **To develop a centralized internship management platform** that
  allows OJT coordinators and super administrators to manage industry
  partners, internship postings, student applications, deployments, and
  official documents through a unified web-based administrative panel.

- **To implement a mobile application for students and coordinators**
  that supports Office 365 authentication, internship browsing and
  application, profile and document management, real-time attendance
  logging, and coordinator-side monitoring of student progress.

- **To automate the application-to-deployment workflow** with a
  structured multi-stage pipeline---from submission through interview
  scheduling to final deployment---while enforcing course-specific
  requirements for BSIT, BSTM, and BSHM programs.

- **To integrate biometric attendance verification and hour tracking**
  using facial recognition at clock-in and clock-out, enabling accurate
  recording of rendered hours, company review of attendance logs, and
  compliance with program hour requirements.

- **To provide communication, document management, and analytics
  capabilities** including in-app notifications, announcements, calendar
  events, MOA and endorsement letter tracking, excuse submission, and
  dashboard reporting to support data-driven OJT program management.

### Scope and Limitations

> This study covers the design, development, and deployment of
> PRACTICOM, an integrated web and mobile system for managing On-the-Job
> Training at STI College Ormoc. The scope includes the public
> internship landing page, the administrative web panel for OJT
> coordinators and super administrators, the industry partner web
> portal, and the Flutter mobile application for students and
> coordinators. Functional coverage spans industry partner onboarding
> and approval, internship posting management, student application and
> multi-stage deployment workflow, attendance logging with optional face
> verification, document management for MOAs and endorsement letters,
> in-app notifications, announcements, calendar events, and coordinator
> analytics dashboards.
>
> The system supports three academic programs---BSIT, BSTM, and
> BSHM---with course-specific hour requirements and role-based access
> for students, coordinators, super administrators, and industry
> partners. The backend is built using PHP and MySQL with a REST API
> connecting the mobile client, deployed on XAMPP for local development
> and on practicom.online for production use. External integrations
> within scope include Microsoft Office 365 OAuth for student
> authentication, Face++ for biometric attendance, Semaphore for SMS
> verification, and Maileroo for partner onboarding email. Testing and
> evaluation are limited to the intended users and workflows of STI
> College Ormoc's OJT program.

### Limitations

- Platform coverage --- The system does not include a dedicated mobile
  application for industry partners; host companies access the platform
  exclusively through the web portal, which may limit convenience for
  partners who prefer mobile-based supervision.

- Institutional dependency --- Student authentication relies on
  Microsoft Office 365 accounts issued by STI College Ormoc. Users
  without valid institutional credentials cannot access the mobile
  application, restricting use to enrolled students and authorized
  personnel.

- Internet connectivity requirement --- PRACTICOM requires a stable
  internet connection for all core functions, including attendance
  logging, face verification, and API communication. Offline mode is not
  supported.

- Third-party service dependency --- Biometric attendance verification
  depends on the Face++ API, and partner onboarding relies on Semaphore
  and Maileroo. Service outages, API changes, or connectivity issues
  with these providers may affect system functionality.

- Security implementation --- The current development setup uses MD5
  password hashing for web-based admin and company accounts. While
  prepared statements and token-based mobile authentication are
  implemented, full production-grade security hardening such as HTTPS
  enforcement, CSRF protection, and bcrypt password hashing remains
  recommended for future deployment.

### Review of Related Literature/Studies/Systems

> Several studies highlight the challenges of managing On-the-Job
> Training through manual processes, including delayed application
> processing, inconsistent hour tracking, and limited coordination
> between academic institutions and industry partners. Research on
> internship management systems emphasizes the need for centralized
> platforms that automate application workflows, deployment monitoring,
> and document handling to reduce administrative workload and improve
> record accuracy.
>
> Existing literature on educational technology further supports the use
> of web-based and mobile applications in higher education, noting that
> digital tools improve accessibility, communication, and real-time data
> visibility among students and administrators. Studies on attendance
> monitoring systems also demonstrate that biometric verification, such
> as facial recognition, enhances the reliability of time logging and
> reduces fraudulent attendance records.
>
> Commercial and institutional systems---including general Learning
> Management Systems and Human Resource Information Systems---offer
> partial solutions for student tracking and employee attendance but
> often lack features specific to OJT workflows, such as
>
> multi-stage application pipelines, MOA tracking, course-specific hour
> requirements, and coordinator--company--student collaboration.
> PRACTICOM addresses this gap by integrating internship deployment,
> attendance verification, document management, and analytics into a
> single platform tailored to the OJT requirements of STI College Ormoc.

## METHODOLOGY

### Technical Background

> PRACTICOM was developed using a three-tier client--server architecture
> consisting of a presentation layer, an application layer, and a data
> layer. The presentation layer includes a public website, an
> administrative web panel, an industry partner portal, and a
> cross-platform mobile application. The application layer is
> implemented in PHP, which handles business logic, session management,
> REST API endpoints, and integration with external services. The data
> layer uses MySQL as the relational database management system, storing
> all system records in a centralized database named practicom_db.
>
> The web components were developed using HTML, CSS, and JavaScript,
> with PHP pages served through the Apache web server provided by XAMPP
> during local development. The mobile application was built using the
> Flutter framework and Dart programming language, enabling deployment
> on Android and iOS from a single codebase. Communication between the
> mobile client and the backend is handled through HTTP-based REST API
> calls using JSON-formatted request and response data. Mobile
> authentication uses Bearer token authorization, while web-based admin
> and company accounts use PHP session-based login.
>
> Student authentication is implemented through Microsoft Office 365
> OAuth 2.0 with PKCE, allowing users to sign in using their
> institutional STI accounts. Attendance verification integrates the
> Face++ API for facial recognition during clock-in and clock-out.
> Additional external services include Semaphore for SMS verification
> during partner registration and Maileroo for transactional email
> notifications. The system follows role-based access control,
> distinguishing students, OJT coordinators, super administrators, and
> industry partners. Development was conducted iteratively, with the
> database schema expanded through incremental migration scripts as new
> modules such as attendance tracking, document management, and
> biometric verification were added. The completed system is deployed in
> production at practicom.online.

### Technologies to be Used

> Backend • PHP -- server-side programming and REST API • MySQL --
> database management • Apache (XAMPP) -- local web server
>
> Web Frontend • HTML, CSS, JavaScript -- web pages and admin panel •
> Font Awesome -- icons
>
> Mobile Application • Flutter (Dart) -- cross-platform mobile app •
> Provider -- state management • Dio / HTTP -- API communication •
> Firebase -- mobile authentication • flutter_secure_storage -- secure
> token storage • file_picker / image_picker -- file and image uploads
>
> External Services • Microsoft Office 365 (Azure AD) -- student login •
> Face++ API -- facial recognition for attendance • Semaphore -- SMS
> verification • Maileroo -- email notifications
>
> Development Tools • XAMPP -- local development • phpMyAdmin --
> database management • Android Studio / VS Code -- code editor • Git --
> version control
>
> Deployment • Hostinger -- production hosting (practicom.online)

### Resources

- Hardware (minimum typical setup)

- Desktop or laptop (development and thesis writing): multi-core CPU, 8
  GB RAM or more, sufficient storage for XAMPP, IDE, Android emulator
  images, and project files.

- Android smartphone and/or iOS device (for real-device testing of
  Flutter).

- Stable network for API calls, Microsoft login testing, and
  documentation access.

### Software

- Windows 10/11 (or equivalent) host OS.

- XAMPP (Apache, MySQL, PHP).

- MySQL / phpMyAdmin (via XAMPP) for schema and data.

- Visual Studio Code or Cursor, Android Studio (Flutter toolchain), Git
  client.

- Web browser (Chrome/Edge/Firefox) for admin and company testing.

- Microsoft Azure / Entra app registration for student OAuth (client ID,
  redirect URI, as configured).

- Microsoft Office or compatible suite for thesis document; diagram tool
  (Draw.io, Lucidchart, etc.) for models.

> Calendar of Activities

![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image1.png){width="5.938567366579178in"
height="3.3256244531933508in"}

> Requirements Analysis
>
> Who
>
> Students (BSIT, BSTM, BSHM, ACT, etc.) who need internships and must
> submit applications and compliance.
>
> OJT coordinators and administrators who approve partners, manage
> postings, move applications through statuses, monitor attendance and
> documents.
>
> Industry partners (companies) who publish opportunities, review
> applicants, and collaborate after verification.
>
> The development team implementing and maintaining the system. What
>
> Digitize internship discovery, application submission, coordinator
> processing, deployment and hours tracking, attendance monitoring,
> notifications, and partner verification so that status and files are
> stored in one system instead of ad hoc channels.
>
> Where
>
> On campus and remotely: coordinators use web; students and
> coordinators use mobile; companies use the web company portal after
> approval.
>
> Technical environment: school or home network accessing a PHP/MySQL
>
> server (local or deployed host per thesis setup). When
>
> During OJT cycles (application periods, deployment semesters, daily
> attendance while deployed).
>
> Anytime for read-only monitoring dashboards, subject to server
> availability. How
>
> Previously: forms, email, spreadsheets, and face-to-face follow-ups.
>
> With PRACTICOM: users authenticate by role; students apply through the
> app with uploads; staff update records through admin tools; companies
> use the portal; the database holds the single source of truth for
> reporting.
>
> Requirements Documentation
>
> This section records the agreement of intent between the client
> context (STI College Ormoc OJT practice) and the developers on what
> the software should do. The system is accepted when these capabilities
> are demonstrable.
>
> Functional scope
>
> Authentication: student sign-in via Office 365; coordinator/admin
> sign-in on web and mobile; company sign-in on web; role-based access
> to modules.
>
> Student mobile: browse internship posts, view details, apply with
> resume and contact data; view application status; attendance (clock
> in/out, logs, summaries where implemented); notifications; profile
> updates as provided by APIs.
>
> Coordinator / admin web: dashboards and reports (applications,
> deployments, students, hours, documents/MOA, attendance reports);
> manage companies (including approval/rejection); manage posts and
> applications through defined status workflows; user administration as
> implemented.
>
> Company web: after password change and document verification, access
> company portal features (applicants, interns, teams, postings, time
> logs, announcements/calendar as implemented).
>
> Security & data integrity: passwords and tokens handled per design;
> file uploads validated; inactive or wrong-role users blocked.
>
> Storyboard
>
> Student: Opens app → sees login with Office 365 → lands on home →
> opens internships list → taps a card → detail screen → Apply → form +
> file picker
>
> → Submit → success dialog → Applications tab shows Pending.
>
> Coordinator (web): Opens admin login → Dashboard with statistics →
> Applications table → opens one row → status manager → changes status →
> saves → student view eventually reflects update.
>
> Coordinator (mobile): Admin login → dashboard tiles → Attendance
> monitor list → taps student → history sheet.
>
> Company: Company login → if required, change password → upload
> verification documents → sees pending message → after school approval,
> company portal dashboard with applicants/interns.
>
> **Design of Software, System, Product, and/or Processes**
>
> In this part, the developers shall describe in detail how they
> designed the system in accordance with standards.
>
> **Development**
>
> In this part, the developers shall describe in detail how they
> developed the system in accordance with standards.

## RESULTS AND DISCUSSION

> **Testing**
>
> In this part, the proponents shall discuss and test the software
> development standards.
>
> **Description of Prototype**
>
> This part includes the system requirements, the preliminary design,
> and how the system is being evaluated and tested.
>
> **Implementation Plan**
>
> The Implementation Plan describes how the information system will be
> deployed, installed, and transitioned into an operational system. The
> plan contains an overview of the system, a brief description of the
> major tasks involved in the implementation, the overall resources
> needed to support the implementation effort (such as hardware,
> software, facilities, materials, and personnel), and any site-specific
> implementation requirements.
>
> **Implementation Results**
>
> This part consists of the outputs during the implementation phase.
> These may include the generated outcomes as the ground for improving
> the project/system. This part is optional.

## CONCLUSION

## 

> **REFERENCES**
>
> **Internship Management System for Communication Between Students and
> Educational Institutions**
>
> **This study discusses how an Internship Management System improves
> communication between students, schools, and companies while
> digitizing internship processes. It highlights faster information
> exchange and better internship**
>
> **management. International Journal of Progressive Research in Science
> and Engineering**
>
> **Using Internship Management System to Improve the Relationship
> between Internship Seekers, Employers and Educational Institutions**
>
> **This paper explains how internship systems help students, employers,
> and educational institutions collaborate more efficiently through
> centralized digital platforms. It also discusses student-company
> matching and internship monitoring.**
>
> **ENTRENOVA Research Journal**
>
> **The Development of an Integrated Cloud-based System to Enhance
> Internship Management**
>
> **This study focuses on improving attendance monitoring, performance
> tracking, and**
>
> **internship coordination through an integrated web-based management
> system. ResearchGate -- Integrated Cloud-based Internship Management
> System
> [[https://journal.ijprse.com/index.php/ijprse/article/view/]{.underline}](https://journal.ijprse.com/index.php/ijprse/article/view/518?utm_source=chatgpt.com)
> [[https://ojs.srce.hr/index.php/entrenova/article/view/]{.underline}](https://ojs.srce.hr/index.php/entrenova/article/view/13437?utm_source=chatgpt.com)**
>
> [**[https://jati.apu.edu.my/index.php/JATI/article/view/]{.underline}**](https://jati.apu.edu.my/index.php/JATI/article/view/260?utm_source=chatgpt.com)
>
> **APPENDICES DESIGN MODEL**
>
> **TRADITIONAL MODEL**

![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image2.jpeg){width="7.533331146106737in"
height="4.199998906386702in"}

### Proposed Design Model

![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image3.png){width="4.833889982502187in"
height="8.298957786526683in"}

> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image4.png){width="2.618588145231846in"
> height="7.804270559930009in"}
>
> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image5.jpeg){width="2.8521456692913385in"
> height="9.5875in"}
>
> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image6.png){width="2.574102143482065in"
> height="10.302083333333334in"}
>
> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image7.jpeg){width="2.6675174978127734in"
> height="9.5275in"}
>
> **APPENDIX A. RESOURCE PERSON**
>
> **APPENDIX B. RELEVANT SOURCE CODE**
>
> **APPENDIX C. EVALUATION TOOL/TEST DOCUMENTS**
>
> **APPENDIX D. SAMPLE INPUT/OUTPUT/REPORTS**
>
> **APPENDIX E. USER'S GUIDE**
>
> **APPENDIX F. PERSONAL TECHNICAL VITAE**
>
> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image8.jpeg){width="6.67887467191601in"
> height="8.341666666666667in"}
>
> Curriculum Vitae of
>
> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image9.jpeg){width="1.2465277777777777in"
> height="1.2402766841644794in"}Curriculum Vitae of

# JOHN LLOYD B. GAJOL

### Brgy. Alegria, Ormoc City, Leyte

> [**[johnlloydgajol@gmail.com]{.underline}**](mailto:johnlloydgajol@gmail.com)
>
> **+63 936 411 3985**
>
> EDUCATIONAL BACKGROUND

+--------------------+---------------+---------------------+
| Level              | Inclusive     | Name of             |
|                    | Dates         | school/Instituition |
+====================+===============+=====================+
| Tertiary           | 2021 -        | STI College of      |
|                    | present       | Ormoc               |
+--------------------+---------------+---------------------+
| Vocation/Technical | > 2019-2021   | STI College of      |
|                    |               | Ormoc               |
+--------------------+---------------+---------------------+
| High School        | > 2015-2019   | > ST. Peters        |
|                    |               | > College of Ormoc  |
+--------------------+---------------+---------------------+
| Elementary         | > 2009-2015   | Lorenzo Y Palo      |
|                    |               | Elem.               |
|                    |               |                     |
|                    |               | School .            |
+--------------------+---------------+---------------------+

> AFFILIATIONS

+----------------+----------------+----------------+
| Inclusive      | Name of        | > Position     |
| Dates          | Organization   |                |
+================+================+================+
| 2024-present   | IT CLUB        | > Member       |
+----------------+----------------+----------------+

> SKILLS

+----------------+----------------+---------------+
| Skills         | Level of       | Date acquired |
|                | Competency     |               |
+:===============+================+===============+
| > Programming  | Basic          | January 2025  |
| > Languages    |                |               |
| >              |                |               |
| > -- PHP, C#,  |                |               |
| > HTML, CSS    |                |               |
+----------------+----------------+---------------+
| Database --    | Basic          | January 2022  |
| MySql, SqLite  |                |               |
+----------------+----------------+---------------+

> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image10.jpeg){width="1.1708333333333334in"
> height="1.2124857830271216in"}Curriculum Vitae of

# JUDE Q. MOJADO

> **Brgy. Linao, Ormoc City, Leyte [[mojado.371520@
> ormoc.sti.edu.ph]{.underline}](mojado.371520%40%20ormoc.sti.edu.ph)
> 09073017987**
>
> EDUCATIONAL BACKGROUND

+--------------------+---------------+-----------------------+
| Level              | Inclusive     | > Name of             |
|                    | Dates         | > school/Instituition |
+====================+===============+:======================+
| Tertiary           | 2022-present  | > STI College of      |
|                    |               | > Ormoc               |
+--------------------+---------------+-----------------------+
| Vocation/Technical | 2020-2022     | > STI College of      |
|                    |               | > Ormoc               |
+--------------------+---------------+-----------------------+
| High School        | 2015-2020     | > Linao National High |
|                    |               | > School              |
+--------------------+---------------+-----------------------+
| Elementary         | 2009-2015     | > Linao Central       |
|                    |               | > School              |
+--------------------+---------------+-----------------------+

> AFFILIATIONS

+----------------+----------------+----------------+
| Inclusive      | Name of        | > Position     |
| Dates          | Organization   |                |
+================+================+================+
| 2024-present   | IT CLUB        | > Member       |
+----------------+----------------+----------------+

> SKILLS

+----------------+----------------+---------------+
| Skills         | Level of       | Date acquired |
|                | Competency     |               |
+:===============+================+===============+
| > Programming  | Basic          | January 2025  |
| > Languages    |                |               |
| >              |                |               |
| > -- PHP, C#,  |                |               |
| > HTML, CSS    |                |               |
+----------------+----------------+---------------+
| Database --    | Basic          | January 2022  |
| MySql, SqLite  |                |               |
+----------------+----------------+---------------+

> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image11.jpeg){width="1.1666666666666667in"
> height="1.1666666666666667in"}Curriculum Vitae of

# JOSE ANDREW MIGUEL P. ROMO

### Brgy. Ipil, Ormoc City, Leyte

> [**[jamromo121@gmail.com]{.underline}**](jamromo121%40gmail.com)

### +63 921 738 6499

> EDUCATIONAL BACKGROUND

+--------------------+---------------+---------------------+
| Level              | Inclusive     | Name of             |
|                    | Dates         | school/Instituition |
+====================+===============+=====================+
| Tertiary           | 2021 -        | STI College of      |
|                    | present       | Ormoc               |
+--------------------+---------------+---------------------+
| Vocation/Technical | > 2019-2021   | STI College of      |
|                    |               | Ormoc               |
+--------------------+---------------+---------------------+
| High School        | > 2015-2019   | > ST. Peters        |
|                    |               | > College of        |
|                    |               |                     |
|                    |               | Ormoc               |
+--------------------+---------------+---------------------+
| Elementary         | > 2009-2015   | Lorenzo Y Palo      |
|                    |               | Elem.               |
|                    |               |                     |
|                    |               | School .            |
+--------------------+---------------+---------------------+

> AFFILIATIONS

+----------------+----------------+----------------+
| Inclusive      | Name of        | > Position     |
| Dates          | Organization   |                |
+================+================+================+
| 2024-present   | IT CLUB        | > Member       |
+----------------+----------------+----------------+

> SKILLS

+----------------+----------------+---------------+
| Skills         | Level of       | Date acquired |
|                | Competency     |               |
+:===============+================+===============+
| > Programming  | Basic          | January 2025  |
| > Languages    |                |               |
| >              |                |               |
| > -- PHP, C#,  |                |               |
| > HTML, CSS    |                |               |
+----------------+----------------+---------------+
| Database --    | Basic          | January 2022  |
| MySql, SqLite  |                |               |
+----------------+----------------+---------------+

> ![](c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media/media/image12.jpeg){width="1.1374989063867016in"
> height="1.1374989063867016in"}Curriculum Vitae of

# STANLEY ARGY M. SOCORIN

### Brgy. Ipil, Ormoc City, Leyte

> [**[jamromo121@gmail.com]{.underline}**](jamromo121%40gmail.com)

### +63 921 738 6499

> EDUCATIONAL BACKGROUND

+--------------------+---------------+---------------------+
| Level              | Inclusive     | Name of             |
|                    | Dates         | school/Instituition |
+====================+===============+=====================+
| Tertiary           | 2019 -        | STI College of      |
|                    | present       | Ormoc               |
+--------------------+---------------+---------------------+
| Vocation/Technical | 2017-2019     | STI College of      |
|                    |               | Ormoc               |
+--------------------+---------------+---------------------+
| High School        | 2012-2017     | > St. Dominic Savio |
|                    |               | >                   |
|                    |               | > International     |
|                    |               | > School            |
+--------------------+---------------+---------------------+
| Elementary         | 2006-2012     | Kinderland inc.     |
+--------------------+---------------+---------------------+

> AFFILIATIONS

+----------------+----------------+----------------+
| Inclusive      | Name of        | > Position     |
| Dates          | Organization   |                |
+================+================+================+
| 2024-present   | IT CLUB        | > Member       |
+----------------+----------------+----------------+

> SKILLS

+----------------+----------------+---------------+
| Skills         | Level of       | Date acquired |
|                | Competency     |               |
+:===============+================+===============+
| > Programming  | Basic          | January 2025  |
| > Languages    |                |               |
| >              |                |               |
| > -- PHP, C#,  |                |               |
| > HTML, CSS    |                |               |
+----------------+----------------+---------------+
| Database --    | Basic          | January 2022  |
| MySql, SqLite  |                |               |
+----------------+----------------+---------------+
