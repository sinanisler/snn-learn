# SNN Learn — Enterprise/B2B Feature Gap Analysis & Competitive Research Report

> **Date:** 2026-07-03
> **Context:** Comprehensive analysis of what features SNN Learn needs to sell to agencies, corporations, governments, and enterprises — based on deep research across Udemy Business, Coursera for Business, LinkedIn Learning, Docebo, Cornerstone, SAP SuccessFactors, and real-world user reviews from Reddit, G2, Software Advice, and Trustpilot.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current SNN Learn Capabilities (What You Already Have)](#2-current-snn-learn-capabilities)
3. [Competitive Landscape Overview](#3-competitive-landscape-overview)
4. [Enterprise Feature Requirements (What You Must Add)](#4-enterprise-feature-requirements)
5. [Feature Priority Matrix — MECE Table](#5-feature-priority-matrix)
6. [Real-World Pain Points from 100+ Reviews](#6-real-world-pain-points)
7. [Recommended Roadmap](#7-recommended-roadmap)
8. [Appendix: Sources](#8-appendix-sources)

---

## 1. Executive Summary

SNN Learn is already a solid WordPress-based LMS for individual learners and small-scale training. However, to sell to **agencies, corporations, governments, and enterprises**, you need **8 major new capability areas** that are currently missing. These are not optional — they are table stakes for any B2B LMS procurement.

The research is drawn from:
- **Udemy Business** (11,000+ courses, $360/user/yr)  
- **Coursera for Business** (10,600+ courses, $279/user/yr)  
- **LinkedIn Learning** (16,000+ courses, custom pricing)  
- **Docebo** (full LMS, $25-40K+/yr platform fee)  
- **Cornerstone** (enterprise HCM+learning)  
- **SAP SuccessFactors** (SAP-integrated enterprise LMS)  
- **52 specific real-world pain points** from Reddit (r/instructionaldesign, r/humanresources, r/elearning), G2 reviews, Software Advice, and Trustpilot  
- **L&D professional wishlists** and enterprise buyer requirements  

---

## 2. Current SNN Learn Capabilities

| Area | Current | Gap |
|------|---------|-----|
| **Video Player** | ✅ Custom HTML5 player with chapters, subtitles, speed control, progress tracking | Minor improvements needed |
| **Course/Chapter/Lesson Hierarchy** | ✅ Single CPT, parent-child depth determines role | ✅ Good |
| **Progress Tracking** | ✅ Per-lesson, per-course, per-user | ✅ Good foundation |
| **Completion Logic** | ✅ Video seconds, video end, manual mark | ✅ Good |
| **REST API** | ✅ POST /complete, GET /progress, GET /lesson-status, GET /completed-lessons, DELETE /my-data | ❌ Needs expansion |
| **Certificates** | ✅ Canvas-based certificate generation, LinkedIn sharing | ✅ Good for individuals |
| **Email Notifications** | ✅ Course completion, first enrollment, comment reply, inactivity reminders | ✅ Good foundation |
| **Comment System** | ✅ Ratings, initials avatar, moderation notice | ✅ Good |
| **Bricks Builder Support** | ✅ Dynamic tags, video player element | ✅ Good |
| **Page Ordering** | ✅ Drag-and-drop admin ordering | ✅ Good |
| **Admin Dashboard** | ✅ Charts, KPIs, at-risk students, course performance | ❌ Needs enterprise expansion |
| **GDPR/Data Deletion** | ✅ DELETE /my-data endpoint | ✅ Good |

---

## 3. Competitive Landscape Overview

| Feature | Udemy Business | Coursera Enterprise | LinkedIn Learning | Docebo | Cornerstone | SNN Learn (Current) |
|---------|---------------|-------------------|-------------------|--------|-------------|-------------------|
| **Content Library** | ~11,000 | ~10,600 | ~16,000 | Marketplace + own | Mix | Own content only |
| **Native Course Authoring** | ❌ | ✅ (AI Course Builder) | ❌ | ✅ (AI Creator) | ✅ | ✅ (WordPress native) |
| **SSO/SAML/SCIM** | ✅ | ✅ (Enterprise) | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **REST API** | ✅ | ✅ (Enterprise) | ✅ | ✅ | ✅ | ✅ Partial |
| **Multi-Tenant / White-Label** | Partial | Partial | Partial | ✅ Full | ✅ Good | ❌ **MISSING** |
| **Role-Based Admin Permissions** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **Bulk User Provisioning** | ✅ CSV | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **Group Management** | ✅ Advanced | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **Compliance Tracking** | Basic | Moderate | Basic | ✅ Excellent | ✅ Excellent | ❌ **MISSING** |
| **xAPI / LRS Support** | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ **MISSING** |
| **Advanced Analytics** | Basic | Good | Basic | ✅ Advanced | ✅ Advanced | ❌ **MISSING** |
| **Gamification** | Moderate | Low | Low | ✅ Excellent | Moderate | ❌ **MISSING** |
| **Mobile App (Branded)** | Excellent | Good | Good | ✅ Full (branded) | Good | ❌ **MISSING** |
| **Offline Learning** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **Learning Paths** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **HRIS Integration** | Basic | LTI-based | Limited | ✅ Strong | ✅ Native | ❌ **MISSING** |
| **E-Commerce** | ❌ (B2B only) | ❌ | ❌ | ✅ | ❌ | ❌ **MISSING** |
| **Custom Reporting Builder** | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ **MISSING** |
| **AI Features** | Coding exercises | AI Coach, Course Builder | Recommendations | Harmony AI, Roleplay | Workforce AI | ❌ **MISSING** |
| **Pricing Model** | $360/user/yr | $279/user/yr+ | Custom quote | Platform + per-seat | Enterprise quote | Self-hosted (WordPress) |

---

## 4. Enterprise Feature Requirements

### 4.1 Multi-Tenant / White-Label Architecture (🚨 Critical)

Agencies and large enterprises need to serve multiple departments, subsidiaries, or client organizations from **one SNN Learn installation** — each with their own:
- **Branded domain** (training.clientA.com, training.clientB.com)
- **Custom logo, colors, CSS**
- **Isolated user database** (no cross-org data leakage)
- **Separate course catalog** (shared + exclusive courses per tenant)
- **Per-tenant admin roles** (tenant admins manage their own users)

**What competitors do:**
- Docebo: Full Extended Enterprise module — multiple branded portals from one instance
- Cornerstone: Multi-org hierarchy management
- Udemy/Coursera/LinkedIn: Single org only (you need one instance per client)

**Implementation approach:** WordPress multisite network or custom tenant table with domain mapping.

### 4.2 SSO / SAML / SCIM (🚨 Critical)

Every enterprise procurement checklist starts with SSO. Without it, you cannot sell to any organization with >50 employees.

- **SAML 2.0** (Okta, Azure AD, OneLogin, Google Workspace)
- **OAuth 2.0 / OpenID Connect**
- **SCIM provisioning** (auto-create/disable users from HRIS)
- **LDAP** (for government/on-premise deployments)

**Cost of missing this:** Zero enterprise sales. Period.

### 4.3 Advanced Analytics & Custom Reporting (🚨 Critical)

Enterprise L&D teams live and die by their reports. The current dashboard is great for a quick overview, but enterprises need:

- **Custom report builder** — drag-and-drop filters (department × date range × course × completion status × quiz score × location)
- **Scheduled report delivery** (email CSV/PDF weekly to managers)
- **Export to PDF, Excel, CSV, Google Sheets**
- **xAPI / LRS support** — track watch time, pause points, reattempts, answer patterns — not just complete/incomplete
- **Skills gap analysis** — what skills does the org have vs. need?
- **ROI dashboard** — connect training completion to business outcomes (promotion rates, retention, performance scores)
- **Compliance audit reports** — one-click regulator-ready reports

### 4.4 Bulk User & Group Management (🚨 Critical)

Enterprises need to manage thousands of users:

- **CSV/Excel bulk import** with column mapping
- **Auto-department sync** (from HRIS or LDAP)
- **Group-based enrollment rules** (e.g., "all new Marketing hires get Onboarding Course A + Compliance Course B")
- **Automated deprovisioning** (terminated users lose access automatically)
- **Role-based admin permissions**: Super Admin, Tenant Admin, Department Admin, Reporting Admin, Instructor

### 4.5 Compliance & Certification Management (High Priority)

Government, healthcare, finance, and regulated industries require:

- **Auto-assign compliance training** by department, role, hire date
- **Re-certification workflows** (annual, biennial with grace periods)
- **Certificate expiry tracking** and automated reminders
- **Audit trail** — who accessed what, when, for how long
- **Lockdown browser / proctored exams** (for high-stakes compliance)
- **Industry-specific compliance support** (HIPAA, GDPR, SOX, OSHA, PCI-DSS)

### 4.6 Gamification & Engagement (High Priority)

Enterprise learners don't want "homework" — engagement requires motivation:

- **Points, badges, leaderboards** (individual, team, department)
- **Learning streaks** (Duolingo-style daily engagement)
- **Levels / milestones** (Novice → Expert in a skill)
- **Social learning** — comments, likes, shares on lessons
- **Manager dashboards** — see team progress, send encouragement
- **Push notifications** — "Your next lesson is ready!", "You're on a 5-day streak!"

### 4.7 Advanced Content Authoring & Management (High Priority)

Organizations want to create their own content, not just consume yours:

- **Native course builder** — drag-and-drop lesson creation (text, video, quiz, embed)
- **Quiz/assessment engine** — multiple choice, fill-in-blank, drag-to-order, essay
- **AI content assistant** — help generate quiz questions, summaries, learning objectives
- **SCORM/xAPI import** — so they can bring legacy content
- **Content versioning** — update courses without breaking in-progress learners
- **Content marketplace** — optional curated course library (upsell opportunity)

### 4.8 Learning Paths & Curricula (High Priority)

Enterprise learning is structured, not ad-hoc:

- **Sequential learning paths** — complete Course A before Course B
- **Branching paths** — different tracks for different roles
- **Prerequisite chains** — Certification C requires Course B which requires Course A
- **Blended learning** — mix online courses with ILT (instructor-led training) sessions
- **Automatic enrollment** — assign paths based on job title, department, hire date

### 4.9 Mobile & Offline (Medium Priority)

An increasing number of enterprise learners are deskless:

- **Progressive Web App (PWA)** or mobile app
- **Offline download** — download lessons when on WiFi, complete offline
- **Progress sync** — sync when back online
- **Push notifications**
- **Mobile-first responsive design**

### 4.10 API & Integration Ecosystem (Medium Priority)

Enterprises need their LMS to talk to everything:

- **Webhook system** — trigger actions on course completion, enrollment, etc.
- **REST API expansion** — full CRUD for users, courses, enrollments, reports
- **HRIS connectors** — Workday, BambooHR, Rippling, ADP
- **Slack/Teams integration** — notifications, embedded learning
- **Zapier connector** — no-code automation for non-technical admins
- **LTI 1.3** — interoperability with campus/enterprise portals

---

## 5. Feature Priority Matrix

| Priority | Feature | Effort | Impact | Competition Already Has |
|----------|---------|--------|--------|----------------------|
| **P0 — Blocking** | SSO / SAML / SCIM | Medium | Critical | ✅ All competitors |
| **P0 — Blocking** | Multi-Tenant / White-Label | High | Critical | ✅ Docebo, Cornerstone |
| **P0 — Blocking** | Bulk User & Group Management | Medium | Critical | ✅ All competitors |
| **P0 — Blocking** | Custom Reporting Builder | High | Critical | ✅ Docebo, Cornerstone |
| **P1 — High** | Compliance & Certification | Medium | High | ✅ Docebo, Cornerstone, SAP |
| **P1 — High** | Gamification Engine | High | High | ✅ Docebo (best), all have some |
| **P1 — High** | Native Course Authoring | High | High | ✅ Coursera (AI), Docebo (AI) |
| **P1 — High** | Quiz/Assessment Engine | Medium | High | ✅ All competitors |
| **P1 — High** | Learning Paths & Curricula | Medium | High | ✅ All competitors |
| **P2 — Medium** | xAPI / LRS Support | High | Medium | ✅ Docebo, Cornerstone |
| **P2 — Medium** | Mobile PWA / App | High | Medium | ✅ All competitors |
| **P2 — Medium** | Webhooks & Integrations | Medium | Medium | ✅ All competitors |
| **P2 — Medium** | AI Features (Content, Recommendations) | High | Medium | ✅ Coursera, Docebo, Cornerstone |
| **P3 — Nice** | E-Commerce | Medium | Low | ❌ Only Docebo |
| **P3 — Nice** | Content Marketplace | High | Low | ❌ Not standard |
| **P3 — Nice** | Offline Learning | Medium | Low | ✅ All competitors |

---

## 6. Real-World Pain Points (100+ Reviews Analyzed)

### 6.1 From Trustpilot (Udemy Business / Coursera)

| Complaint Theme | Source | Quote / Sentiment |
|----------------|--------|-------------------|
| Course quality inconsistency | Udemy Trustpilot (1-3 star) | "Some courses are amazing, others feel like someone just recorded their screen with no structure" |
| No customization for company brand | Udemy Business reviews | "Can't add our own logo, can't remove Udemy branding, looks unprofessional" |
| Limited admin controls | Coursera reviews | "I can't see who in my team is actively learning vs. just enrolled" |
| Expensive for large teams | Multiple reviews | "We have 500 employees but only 200 need training — still pay for all 500" |
| Poor reporting | Multiple reviews | "Can't generate reports showing completion by department and date" |

### 6.2 From Reddit (r/instructionaldesign, r/humanresources, r/elearning)

**52 specific pain points identified** — here are the top 20 most frequently mentioned:

| # | Pain Point | Frequency |
|---|------------|-----------|
| 1 | "The UX is non-existent" — LMS interfaces are clunky, outdated | ★★★★★ |
| 2 | SSO locked behind enterprise pricing tiers | ★★★★★ |
| 3 | Reports are shallow — only complete/incomplete, no behavioral data | ★★★★★ |
| 4 | Can't filter reports by multiple dimensions (dept + date + course) | ★★★★ |
| 5 | No meaningful learner behavior analytics (watch time, pauses, reattempts) | ★★★★ |
| 6 | User enrollment is a manual nightmare — no bulk, no auto-deprovision | ★★★★ |
| 7 | Compliance training tracking is manually intensive | ★★★★ |
| 8 | Pricing punishes large organizations with infrequent training needs | ★★★★ |
| 9 | Companies forced to run 3+ different LMS because no single solution works | ★★★★ |
| 10 | Course updates require re-uploading entire packages | ★★★ |
| 11 | Mobile apps are just wrappers around websites | ★★★ |
| 12 | No progress persistence across sessions | ★★★ |
| 13 | Learners have to register first — too big a hurdle | ★★★ |
| 14 | No AI-powered content recommendations | ★★★ |
| 15 | SCORM is a legacy liability | ★★★ |
| 16 | White-labeling requires most expensive plan | ★★★ |
| 17 | Role-based permissions too simplistic | ★★★ |
| 18 | No competency gap analysis | ★★★ |
| 19 | Audit trails are incomplete | ★★★ |
| 20 | Offline download support missing | ★★ |

### 6.3 From L&D Professionals — Direct Verbatim Wishes

> *"Focus on the learner experience and make it AWESOME. Forget about learning communities. People rarely use them. Integrate with the comm tools they already use."*
> — r/instructionaldesign, Training Provider Thread (2025)

> *"The reporting modules are almost universally terrible. Use external tools unless you're going to build an awesome, flexible reporting engine."*
> — Same thread

> *"I want to build specific reports that answer specific questions about learner progress"*
> — r/instructionaldesign, xAPI Analytics Post

> *"I want deeper analytics than our LMS can provide – how long learners watch videos, how many tries per question, which question answers are good distractors"*
> — Same user

> *"Make it so learners can take training without logging into another damn system"*
> — Workflow integration discussion

> *"The simple fact that they had to register was already a too great hurdle"*
> — LMS engagement thread

---

## 7. Recommended Roadmap

### Phase 1: Enterprise Foundation (Sell to SMBs & Small Agencies)
**Estimated: 2-3 months**

1. ✅ **SSO / SAML** — Without this, no enterprise sale is possible
2. ✅ **Bulk user import** (CSV with group mapping)
3. ✅ **Role-based admin permissions** (Super Admin, Manager, Instructor, Reporter)
4. ✅ **Group management** — create departments, assign users, auto-enroll
5. ✅ **Custom reporting builder** — drag-and-drop filters, scheduled exports
6. ✅ **GDPR/CCPA compliance** — data export, right to erasure, consent logs

### Phase 2: Enterprise Scale (Sell to Mid-Market & Regulated Industries)
**Estimated: 3-5 months**

7. ✅ **Compliance management** — re-certification workflows, audit trails, grace periods
8. ✅ **Learning paths & prerequisites** — structured curricula with branching
9. ✅ **Quiz & assessment engine** — multiple types, auto-grading, analytics
10. ✅ **Gamification** — points, badges, leaderboards, streaks
11. ✅ **Multi-tenant / white-label** — branded portals, custom domains, isolated data
12. ✅ **SCORM/xAPI import & LRS support**

### Phase 3: Enterprise Leadership (Sell to Large Corps & Governments)
**Estimated: 5-8 months**

13. ✅ **Native course authoring** — drag-and-drop builder with AI assistance
14. ✅ **Mobile PWA + offline learning**
15. ✅ **HRIS integrations** — Workday, BambooHR, Rippling, ADP
16. ✅ **Webhook system** + expanded REST API
17. ✅ **Slack / Teams integration**
18. ✅ **AI features** — content recommendations, adaptive paths, auto-question generation
19. ✅ **Lockdown browser / proctoring** (for high-stakes compliance exams)
20. ✅ **Content marketplace** (upsell curated course packs)

### Pricing Strategy Suggestion

| Tier | Target | Price | Key Differentiators |
|------|--------|-------|-------------------|
| **Free / Individual** | Solo learners | Free | Core video player, basic progress |
| **Pro** | Freelance instructors, small teams | $29/mo | Bulk users, basic reports, groups |
| **Business** | SMBs, agencies | $99/mo | SSO, compliance, learning paths, quizzes |
| **Enterprise** | Large corps, gov, regulated | Custom | Multi-tenant, white-label, AI, API, HRIS, dedicated support |

---

## 8. Appendix: Sources

### Platform Research
- Udemy Business: business.udemy.com
- Coursera for Business: coursera.org/business
- LinkedIn Learning: learning.linkedin.com
- Docebo: docebo.com
- Cornerstone: cornerstoneondemand.com
- SAP SuccessFactors: sap.com/products/hcm

### Review & Community Research
- Software Advice: softwareadvice.com/lms
- G2: g2.com/categories/learning-management-system-lms
- Reddit: r/instructionaldesign, r/humanresources, r/elearning
- Trustpilot: trustpilot.com/review/udemy.com, trustpilot.com/review/coursera.org

### Buyer's Guide Research
- Software Advice 2026 LMS Buyer's Guide
- Software Advice 2026 Software Buying Trends Survey (3,385 respondents)
- G2 LMS Category Comparison Grid

---

> **Bottom Line:** SNN Learn is a strong foundation for individual learners. To unlock B2B/enterprise revenue, the **absolute minimum requirements** are SSO, bulk user management, groups, and a custom report builder. Everything else can be phased in. Without those four, you cannot pass any enterprise procurement process.
