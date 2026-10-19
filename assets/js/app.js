document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".notice").forEach((notice) => {
        window.setTimeout(() => {
            notice.classList.add("is-dismissing");
            notice.addEventListener("transitionend", () => notice.remove(), { once: true });
        }, 4000);
    });

    const toggle = document.getElementById("darkModeToggle");
    const darkModeItem = document.querySelector(".dark-mode-item");
    const mobileMenu = document.getElementById("mobileMenu");
    const sidebar = document.getElementById("sidebar");
    const sidebarToggle = document.querySelector("[data-sidebar-toggle]");
    const schoolSwitcher = document.getElementById("schoolSwitcher");
    const schoolMenu = document.getElementById("schoolMenu");
    const philippineTime = document.getElementById("philippineTime");

    if (philippineTime) {
        const timeText = philippineTime.querySelector("span");
        const formatter = new Intl.DateTimeFormat("en-PH", {
            timeZone: "Asia/Manila",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: false
        });
        const updatePhilippineTime = () => {
            if (timeText) timeText.textContent = `PHT ${formatter.format(new Date())}`;
        };

        updatePhilippineTime();
        window.setInterval(updatePhilippineTime, 1000);
    }

    const savedTheme = localStorage.getItem("clearance-theme");

    if (savedTheme === "dark") {
        document.body.classList.add("dark");
        if (toggle) toggle.checked = true;
    }

    if (toggle) {
        toggle.addEventListener("change", () => {
            document.body.classList.toggle("dark", toggle.checked);
            localStorage.setItem(
                "clearance-theme",
                toggle.checked ? "dark" : "light"
            );
        });
    }

    darkModeItem?.addEventListener("click", (event) => {
        if (event.target === toggle || event.target.closest(".switch")) return;
        if (!toggle) return;
        toggle.checked = !toggle.checked;
        toggle.dispatchEvent(new Event("change", { bubbles: true }));
    });

    // Dark Mode controls: sidebar switch + avatar-dropdown button (all roles).
    // Both stay in sync; the dropdown row shows moon/sun like the old tile.
    const profileDarkRow = document.getElementById("profileDarkModeToggle");
    const paintDarkControls = (isDark) => {
        if (toggle) toggle.checked = isDark;
        if (!profileDarkRow) return;
        profileDarkRow.setAttribute("aria-pressed", isDark ? "true" : "false");
        const holder = profileDarkRow.querySelector("[data-dark-icon]");
        if (holder) {
            holder.innerHTML = `<i data-lucide="${isDark ? "sun" : "moon"}"></i>`;
            if (window.lucide) lucide.createIcons();
        }
    };
    if (toggle) {
        toggle.addEventListener("change", () => paintDarkControls(toggle.checked));
    }
    if (profileDarkRow) {
        paintDarkControls(document.body.classList.contains("dark"));
        profileDarkRow.addEventListener("click", () => {
            const isDark = !document.body.classList.contains("dark");
            document.body.classList.toggle("dark", isDark);
            localStorage.setItem("clearance-theme", isDark ? "dark" : "light");
            paintDarkControls(isDark);
        });
    }

    // Mobile "More" bottom sheet (slides up over bottom nav).
    const moreButton = document.getElementById("moreNavButton");
    const moreSheet = document.getElementById("moreSheet");
    const moreBackdrop = document.getElementById("moreSheetBackdrop");
    const moreClose = document.getElementById("moreSheetClose");
    const openMoreSheet = () => {
        if (!moreSheet || !moreBackdrop || !moreButton) return;
        moreSheet.hidden = false;
        moreBackdrop.hidden = false;
        requestAnimationFrame(() => {
            moreSheet.classList.add("open");
            moreBackdrop.classList.add("open");
        });
        moreButton.setAttribute("aria-expanded", "true");
    };
    const closeMoreSheet = () => {
        if (!moreSheet || !moreBackdrop || !moreButton) return;
        moreSheet.classList.remove("open");
        moreBackdrop.classList.remove("open");
        moreButton.setAttribute("aria-expanded", "false");
        window.setTimeout(() => {
            if (!moreSheet.classList.contains("open")) {
                moreSheet.hidden = true;
                moreBackdrop.hidden = true;
            }
        }, 220);
    };
    if (moreButton && moreSheet && moreBackdrop) {
        moreButton.addEventListener("click", (event) => {
            event.stopPropagation();
            if (moreSheet.hidden || !moreSheet.classList.contains("open")) openMoreSheet();
            else closeMoreSheet();
        });
        moreClose?.addEventListener("click", closeMoreSheet);
        moreBackdrop.addEventListener("click", closeMoreSheet);
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !moreSheet.hidden) closeMoreSheet();
        });
    }

    if (mobileMenu && sidebar) {
        mobileMenu.addEventListener("click", () => {
            const isOpen = sidebar.classList.toggle("open");
            mobileMenu.setAttribute("aria-expanded", isOpen ? "true" : "false");
        });
    }

    if (sidebar && (mobileMenu || sidebarToggle)) {
        document.addEventListener("click", (event) => {
            if (window.matchMedia("(max-width: 800px)").matches && sidebar.classList.contains("open") && !sidebar.contains(event.target) && !mobileMenu?.contains(event.target) && !sidebarToggle?.contains(event.target)) {
                sidebar.classList.remove("open");
                mobileMenu?.setAttribute("aria-expanded", "false");
            }
        });
    }

    if (sidebar && sidebarToggle) {
        const setSidebarCollapsed = (collapsed) => {
            document.body.classList.toggle("sidebar-collapsed", collapsed);
            sidebarToggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
            sidebarToggle.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Collapse sidebar");
            localStorage.setItem("clearance-sidebar-collapsed", collapsed ? "true" : "false");
        };

        setSidebarCollapsed(localStorage.getItem("clearance-sidebar-collapsed") === "true");
        sidebarToggle.addEventListener("click", () => {
            // Headers with a single panel toggle (no burger): open the drawer on mobile.
            if (window.matchMedia("(max-width: 800px)").matches && !mobileMenu) {
                sidebar.classList.toggle("open");
                return;
            }
            setSidebarCollapsed(!document.body.classList.contains("sidebar-collapsed"));
        });
    }

    schoolSwitcher?.addEventListener("click", (event) => {
        event.stopPropagation();
        const isOpen = schoolMenu?.classList.toggle("is-open");
        schoolSwitcher.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });

    document.addEventListener("click", (event) => {
        if (schoolMenu && !schoolMenu.contains(event.target) && event.target !== schoolSwitcher) {
            schoolMenu.classList.remove("is-open");
            schoolSwitcher?.setAttribute("aria-expanded", "false");
        }
    });

    const filterTable = (container) => {
        // Skip server-side filter forms (e.g. admin reports.php uses GET + Apply).
        if (!container || container.hasAttribute("data-server-filter") || container.querySelector("[data-server-filter]")) return;
        if (container.tagName === "FORM" && (container.method || "").toLowerCase() === "get") return;
        const table = container?.closest(".content")?.querySelector("table tbody");
        if (!table) return;

        const input = container.querySelector(".search-field input");
        // Skip server-side GET-form dropdowns (e.g. course_filter) — they are not client filters.
        const rawSelect = container.querySelector("select[data-filter]") || container.querySelector("select");
        const select = rawSelect && rawSelect.closest('form[method="get"]') ? null : rawSelect;
        const query = input?.value.toLowerCase().trim() || "";
        const filterValue = select?.value.toLowerCase().replaceAll(" ", "_") || "all";
        const filterType = select?.dataset.filter || "status";

        table.querySelectorAll("tr").forEach((row) => {
            const matchesQuery = row.innerText.toLowerCase().includes(query);
            // No client dropdown (search-only toolbars, or GET-form dropdowns skipped above) = no status filtering.
            let matchesFilter = true;
            if (select) {
                const statusCell = row.querySelector(".status");
                matchesFilter = filterType === "role"
                    ? filterValue === "all" || row.dataset.role === filterValue
                    : filterValue === "all" || filterValue === "all_statuses" || statusCell?.classList.contains(`status-${filterValue}`);
            }
            row.style.display = matchesQuery && matchesFilter ? "" : "none";
        });
    };

    document.querySelectorAll(".search-field input").forEach((input) => {
        input.addEventListener("input", () => filterTable(input.closest(".toolbar-card, .filter-row")));
    });

    const userRole = document.getElementById("userRole");
    const studentFields = document.querySelectorAll(".student-only-field");
    const officeFields = document.querySelectorAll(".office-only-field");
    const collegeDeanToggle = document.getElementById("collegeDeanToggle");
    const collegeDeanCourse = document.querySelector(".dean-course-field");
    const updateStudentFields = () => {
        const visible = userRole?.value === "student";
        studentFields.forEach((field) => field.classList.toggle("is-hidden", !visible));
        studentFields.forEach((field) => {
            const input = field.querySelector("input");
            if (input) input.required = visible && input.name === "student_number";
        });
        officeFields.forEach((field) => {
            const officeRole = userRole?.value === "office";
            const showField = officeRole && (field !== collegeDeanCourse || collegeDeanToggle?.checked);
            field.classList.toggle("is-hidden", !officeRole);
            if (field === collegeDeanCourse) field.classList.toggle("is-hidden", !showField);
            field.hidden = !showField;
            field.style.setProperty("display", showField ? "" : "none", "important");
        });
        if (collegeDeanCourse) {
            const courseSelect = collegeDeanCourse.querySelector("select");
            if (courseSelect) courseSelect.required = userRole?.value === "office" && collegeDeanToggle?.checked;
        }
    };
    userRole?.addEventListener("change", updateStudentFields);
    collegeDeanToggle?.addEventListener("change", updateStudentFields);
    updateStudentFields();

    document.querySelectorAll(".filter-row select").forEach((select) => {
        // Server-side GET filter forms submit via Apply button — don't hijack them.
        const container = select.closest(".filter-row");
        if (container && (container.hasAttribute("data-server-filter") || (container.tagName === "FORM" && (container.method || "").toLowerCase() === "get"))) return;
        select.addEventListener("change", () => filterTable(select.closest(".filter-row")));
    });

    const closeForm = (form) => {
        if (!form) return;
        form.classList.remove("is-open");
        document.body.classList.remove("modal-open");
        if (form.id === "clearance-review-form") {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.delete("review");
            currentUrl.hash = "";
            window.history.replaceState({}, document.title, `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`);
        }
    };

    document.querySelectorAll("[data-form-target]").forEach((trigger) => {
        trigger.addEventListener("click", (event) => {
            const form = document.getElementById(trigger.dataset.formTarget);
            if (!form) return;
            event.preventDefault();
            form.classList.add("is-open");
            document.body.classList.add("modal-open");
            form.querySelector("input:not([type='hidden']), textarea, select")?.focus();
        });
    });

    const configureClearanceButton = document.getElementById("configureClearanceButton");
    configureClearanceButton?.addEventListener("click", (event) => {
        const form = document.getElementById("clearance-review-form");
        if (!form) return;

        event.preventDefault();
        form.classList.add("is-open");
        document.body.classList.add("modal-open");
        form.querySelector("input:not([type='hidden']), textarea, select")?.focus();
    });

    document.querySelectorAll(".floating-form-close").forEach((button) => {
        button.addEventListener("click", () => closeForm(button.closest(".floating-form")));
    });

    document.querySelectorAll(".floating-form").forEach((form) => {
        form.addEventListener("click", (event) => {
            if (event.target === form) closeForm(form);
        });
    });

    const deleteModal = document.getElementById("deleteConfirmModal");
    const deleteMessage = document.getElementById("deleteConfirmMessage");
    const deleteCancel = document.getElementById("deleteConfirmCancel");
    const deleteSubmit = document.getElementById("deleteConfirmSubmit");
    const deleteIcon = document.getElementById("deleteConfirmIcon");
    const deleteTitle = document.getElementById("deleteConfirmTitle");
    let pendingDeleteForm = null;
    let pendingDeleteHref = null;
    let pendingPrint = false;

    const openConfirmModal = (el) => {
        const isWarning = el.dataset.confirmKind === "warning";
        if (deleteMessage) deleteMessage.textContent = el.dataset.deleteConfirm || "Are you sure?";
        if (deleteTitle) deleteTitle.textContent = el.dataset.confirmTitle || (isWarning ? "Are you sure?" : "Delete record?");
        if (deleteIcon) {
            deleteIcon.classList.toggle("is-warning", isWarning);
            deleteIcon.innerHTML = `<i data-lucide="${el.dataset.confirmIcon || (isWarning ? "circle-help" : "trash-2")}"></i>`;
        }
        if (deleteSubmit) {
            deleteSubmit.classList.toggle("btn-danger", !isWarning);
            deleteSubmit.classList.toggle("btn-primary", isWarning);
            if (el.dataset.confirmAction) deleteSubmit.textContent = el.dataset.confirmAction;
            else deleteSubmit.innerHTML = '<i data-lucide="trash-2"></i>Delete';
            if (window.lucide) lucide.createIcons();
        }
        deleteModal?.classList.add("is-open");
        deleteModal?.setAttribute("aria-hidden", "false");
        document.body.classList.add("modal-open");
        deleteCancel?.focus();
    };

    const closeDeleteModal = () => {
        deleteModal?.classList.remove("is-open");
        deleteModal?.setAttribute("aria-hidden", "true");
        document.body.classList.remove("modal-open");
        pendingDeleteForm = null;
        pendingDeleteHref = null;
        pendingPrint = false;
    };

    document.querySelectorAll("form[data-delete-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            pendingDeleteForm = form;
            pendingDeleteHref = null;
            openConfirmModal(form);
        });
    });

    // Links (e.g. Logout): confirm first, then navigate.
    document.querySelectorAll('a[data-delete-confirm]').forEach((link) => {
        link.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            pendingDeleteForm = null;
            pendingDeleteHref = link.getAttribute("href");
            // Tuck away the open profile dropdown behind the modal.
            const profileDropdown = document.getElementById("profileDropdown");
            const profileButton = document.getElementById("profileButton");
            if (profileDropdown) profileDropdown.hidden = true;
            profileButton?.setAttribute("aria-expanded", "false");
            openConfirmModal(link);
        });
    });

    // Print buttons (e.g. Print form, certificates, reports): confirm first.
    document.querySelectorAll("[data-print-confirm]").forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            pendingDeleteForm = null;
            pendingDeleteHref = null;
            pendingPrint = true;
            openConfirmModal(btn);
        });
    });

    deleteCancel?.addEventListener("click", closeDeleteModal);
    deleteSubmit?.addEventListener("click", () => {
        const form = pendingDeleteForm;
        const href = pendingDeleteHref;
        const doPrint = pendingPrint;
        closeDeleteModal();
        if (form) form.submit();
        else if (href) window.location.href = href;
        else if (doPrint) window.print();
    });
    deleteModal?.addEventListener("click", (event) => {
        if (event.target === deleteModal) closeDeleteModal();
    });

    if (document.querySelector(".floating-form.is-open")) {
        document.body.classList.add("modal-open");
        if (new URLSearchParams(window.location.search).has("edit")) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            document.querySelectorAll(".floating-form.is-open").forEach(closeForm);
            closeDeleteModal();
            if (sidebar) {
                sidebar.classList.remove("open");
                mobileMenu?.setAttribute("aria-expanded", "false");
            }
        }
    });

    const sidebarSearch = document.querySelector(".sidebar-search");
    const sidebarSearchInput = document.getElementById("sidebarSearchInput");
    const menuItems = [...document.querySelectorAll(".sidebar-menu .menu-item")];
    const focusSearch = () => {
        if (window.matchMedia("(max-width: 800px)").matches && sidebar && mobileMenu) {
            sidebar.classList.add("open");
            mobileMenu.setAttribute("aria-expanded", "true");
        }
        sidebarSearchInput?.focus();
    };

    sidebarSearch?.addEventListener("click", focusSearch);
    sidebarSearchInput?.addEventListener("click", (event) => event.stopPropagation());
    sidebarSearchInput?.addEventListener("input", () => {
        const query = sidebarSearchInput.value.toLowerCase().trim();
        menuItems.forEach((item) => {
            item.style.display = item.innerText.toLowerCase().includes(query) ? "flex" : "none";
        });
    });
    sidebarSearchInput?.addEventListener("keydown", (event) => {
        if (event.key !== "Enter") return;
        const firstMatch = menuItems.find((item) => item.style.display !== "none");
        if (firstMatch) window.location.href = firstMatch.href;
    });
    // Notifications: bell is now a real link (notifications.php). Only poll the unread badge.
    // Legacy buttons without href still fall back to role pages.
    // Header notifications dropdown (student portal). Falls back to legacy navigation where no dropdown exists.
    const notifButton = document.getElementById("notificationsButton");
    const notifDropdown = document.getElementById("notifDropdown");
    const notifList = document.getElementById("notifList");
    const notifCount = document.querySelector("[data-notif-count]");
    const notifMarkAll = document.getElementById("notifMarkAll");
    const feedBase = () => {
        const p = window.location.pathname;
        return (p.includes("/admin/") || p.includes("/student/") || p.includes("/office/")) ? "../notifications_feed.php" : "notifications_feed.php";
    };
    const renderNotifFeed = (data) => {
        if (!notifList) return;
        const items = Array.isArray(data.items) ? data.items : [];
        if (notifCount) notifCount.textContent = `${Number(data.unread || 0)} unread`;
        notifList.innerHTML = "";
        if (!items.length) {
            const empty = document.createElement("div");
            empty.className = "notif-empty";
            empty.textContent = "No notifications yet.";
            notifList.appendChild(empty);
            return;
        }
        items.forEach((item) => {
            const card = document.createElement("div");
            card.className = "notif-item" + (Number(item.is_read) ? "" : " is-unread");
            const type = document.createElement("span");
            type.className = "notif-item-type";
            type.textContent = (item.type || "notice").replace(/_/g, " ");
            const title = document.createElement("div");
            title.className = "notif-item-title";
            title.textContent = item.title || "(No title)";
            const msg = document.createElement("div");
            msg.className = "notif-item-msg";
            msg.textContent = item.message || "";
            const when = document.createElement("div");
            when.className = "notif-item-when";
            when.textContent = item.when || "";
            card.append(type, title, msg, when);
            if (!Number(item.is_read)) {
                const check = document.createElement("button");
                check.className = "notif-item-check";
                check.type = "button";
                check.setAttribute("aria-label", "Mark as read");
                check.innerHTML = '<i data-lucide="check-check"></i>';
                check.addEventListener("click", async (ev) => {
                    ev.stopPropagation();
                    await markNotifRead(item.id);
                });
                card.appendChild(check);
            }
            notifList.appendChild(card);
        });
        if (window.lucide) lucide.createIcons();
    };
    const refreshNotifFeed = async () => {
        if (!notifDropdown) return;
        try {
            const res = await fetch(feedBase(), { headers: { "Accept": "application/json" } });
            if (!res.ok) return;
            renderNotifFeed(await res.json());
        } catch (e) { /* offline - keep last value */ }
    };
    const postNotifAction = async (params) => {
        // The dropdown only renders on student pages, so a relative POST hits student/notifications.php.
        const body = new URLSearchParams(params);
        try {
            await fetch("notifications.php", { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() });
        } catch (e) { /* offline */ }
    };
    const markNotifRead = async (id) => {
        await postNotifAction({ action: "mark_read", notification_id: String(id) });
        await refreshNotifFeed();
        if (typeof refreshUnread === "function") refreshUnread();
        else {
            const badge = document.querySelector("[data-unread-badge]");
            if (badge) {
                const n = Math.max(0, Number(badge.textContent) - 1 || 0);
                badge.textContent = String(n);
                badge.style.display = n <= 0 ? "none" : "grid";
            }
        }
    };
    const closeNotifDropdown = () => {
        if (!notifDropdown || notifDropdown.hidden) return;
        notifDropdown.hidden = true;
        notifButton?.setAttribute("aria-expanded", "false");
    };
    const profileButton = document.getElementById("profileButton");
    const profileDropdown = document.getElementById("profileDropdown");
    const closeProfileDropdown = () => {
        if (!profileDropdown || profileDropdown.hidden) return;
        profileDropdown.hidden = true;
        profileButton?.setAttribute("aria-expanded", "false");
    };
    if (profileButton && profileDropdown) {
        profileButton.addEventListener("click", (event) => {
            event.stopPropagation();
            const willOpen = profileDropdown.hidden;
            closeNotifDropdown();
            profileDropdown.hidden = !willOpen;
            profileButton.setAttribute("aria-expanded", willOpen ? "true" : "false");
        });
        document.addEventListener("click", (event) => {
            if (!profileDropdown.hidden && !profileDropdown.contains(event.target) && !profileButton.contains(event.target)) closeProfileDropdown();
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closeProfileDropdown();
        });
    }
    if (notifButton && notifDropdown) {
        notifButton.addEventListener("click", (event) => {
            event.stopPropagation();
            const willOpen = notifDropdown.hidden;
            closeProfileDropdown();
            notifDropdown.hidden = !willOpen;
            notifButton.setAttribute("aria-expanded", willOpen ? "true" : "false");
            if (willOpen) refreshNotifFeed();
        });
        document.addEventListener("click", (event) => {
            if (!notifDropdown.hidden && !notifDropdown.contains(event.target) && event.target !== notifButton && !notifButton.contains(event.target)) closeNotifDropdown();
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closeNotifDropdown();
        });
        notifMarkAll?.addEventListener("click", async () => {
            await postNotifAction({ action: "mark_all_read" });
            await refreshNotifFeed();
            const badge = document.querySelector("[data-unread-badge]");
            if (badge) {
                badge.textContent = "0";
                badge.style.display = "none";
            }
        });
    } else {
        notifButton?.addEventListener("click", (event) => {
            const el = event.currentTarget;
            if (el && el.tagName === "A" && el.getAttribute("href")) return; // let the link work
            window.location.href = window.location.pathname.includes("/office/") ? "reviews.php" : "clearance.php";
        });
    }
    const unreadBadge = document.querySelector("[data-unread-badge]");
    const refreshUnread = async () => {
        if (!unreadBadge) return;
        try {
            const base = window.location.pathname.includes("/admin/") || window.location.pathname.includes("/student/") || window.location.pathname.includes("/office/")
                ? "../notifications_unread.php"
                : "notifications_unread.php";
            const res = await fetch(base, { headers: { "Accept": "application/json" } });
            if (!res.ok) return;
            const data = await res.json();
            const n = Number(data.unread || 0);
            unreadBadge.textContent = n > 99 ? "99+" : String(n);
            unreadBadge.style.display = n <= 0 ? "none" : "grid";
        } catch (e) { /* offline - keep last value */ }
    };
    if (unreadBadge) {
        if (unreadBadge.textContent.trim() === "0") unreadBadge.style.display = "none";
        refreshUnread();
        window.setInterval(refreshUnread, 30000);
    }
    const helpButton = document.getElementById("helpButton");
    const helpModal = document.getElementById("helpModal");
    const helpModalMessage = document.getElementById("helpModalMessage");
    const helpModalOk = document.getElementById("helpModalOk");
    const openHelpModal = () => {
        if (!helpModal) return;
        helpModal.classList.add("is-open");
        helpModal.setAttribute("aria-hidden", "false");
        helpModalOk?.focus();
    };
    const closeHelpModal = () => {
        if (!helpModal) return;
        helpModal.classList.remove("is-open");
        helpModal.setAttribute("aria-hidden", "true");
        helpButton?.focus();
    };
    helpButton?.addEventListener("click", () => {
        const isMobile = window.matchMedia("(max-width: 800px)").matches;
        const desktopMessage = helpButton.getAttribute("data-help-message")
            || "Use the sidebar to manage students, offices, users, announcements, and clearance requests.";
        const mobileMessage = helpButton.getAttribute("data-help-message-mobile")
            || "Use the bottom navigation bar and the More tab to manage students, offices, users, announcements, and clearance requests.";
        if (helpModalMessage) helpModalMessage.textContent = isMobile ? mobileMessage : desktopMessage;
        openHelpModal();
    });
    helpModalOk?.addEventListener("click", closeHelpModal);
    helpModal?.addEventListener("click", (event) => {
        if (event.target === helpModal) closeHelpModal();
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && helpModal?.classList.contains("is-open")) closeHelpModal();
    });
    document.addEventListener("keydown", (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
            event.preventDefault();
            focusSearch();
        }
    });

    // Password visibility toggle (lucide eye / eye-off) — all password fields.
    document.querySelectorAll(".password-toggle").forEach((btn) => {
        btn.addEventListener("click", () => {
            const field = btn.closest(".password-field");
            const input = field?.querySelector("input");
            if (!input) return;
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
            btn.innerHTML = `<i data-lucide="${show ? "eye-off" : "eye"}"></i>`;
            if (window.lucide) lucide.createIcons();
            input.focus();
        });
    });
});