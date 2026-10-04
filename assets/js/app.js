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

    if (mobileMenu && sidebar) {
        mobileMenu.addEventListener("click", () => {
            const isOpen = sidebar.classList.toggle("open");
            mobileMenu.setAttribute("aria-expanded", isOpen ? "true" : "false");
        });

        document.addEventListener("click", (event) => {
            if (window.matchMedia("(max-width: 800px)").matches && sidebar.classList.contains("open") && !sidebar.contains(event.target) && !mobileMenu.contains(event.target)) {
                sidebar.classList.remove("open");
                mobileMenu.setAttribute("aria-expanded", "false");
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
        const table = container?.closest(".content")?.querySelector("table tbody");
        if (!table) return;

        const input = container.querySelector(".search-field input");
        const select = container.querySelector("select[data-filter]") || container.querySelector("select");
        const query = input?.value.toLowerCase().trim() || "";
        const filterValue = select?.value.toLowerCase().replaceAll(" ", "_") || "all";
        const filterType = select?.dataset.filter || "status";

        table.querySelectorAll("tr").forEach((row) => {
            const matchesQuery = row.innerText.toLowerCase().includes(query);
            const statusCell = row.querySelector(".status");
            const matchesFilter = filterType === "role"
                ? filterValue === "all" || row.dataset.role === filterValue
                : filterValue === "all_statuses" || statusCell?.classList.contains(`status-${filterValue}`);
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
    let pendingDeleteForm = null;

    const closeDeleteModal = () => {
        deleteModal?.classList.remove("is-open");
        deleteModal?.setAttribute("aria-hidden", "true");
        document.body.classList.remove("modal-open");
        pendingDeleteForm = null;
    };

    document.querySelectorAll("[data-delete-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            pendingDeleteForm = form;
            if (deleteMessage) deleteMessage.textContent = form.dataset.deleteConfirm;
            deleteModal?.classList.add("is-open");
            deleteModal?.setAttribute("aria-hidden", "false");
            document.body.classList.add("modal-open");
            deleteCancel?.focus();
        });
    });

    deleteCancel?.addEventListener("click", closeDeleteModal);
    deleteSubmit?.addEventListener("click", () => {
        const form = pendingDeleteForm;
        closeDeleteModal();
        form?.submit();
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
            if (sidebar && mobileMenu) {
                sidebar.classList.remove("open");
                mobileMenu.setAttribute("aria-expanded", "false");
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
    document.getElementById("notificationsButton")?.addEventListener("click", () => {
        window.location.href = window.location.pathname.includes("/office/") ? "reviews.php" : "clearance.php";
    });
    document.getElementById("helpButton")?.addEventListener("click", () => {
        window.alert("Use the sidebar to manage students, offices, users, announcements, and clearance requests.");
    });
    document.addEventListener("keydown", (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
            event.preventDefault();
            focusSearch();
        }
    });
});
