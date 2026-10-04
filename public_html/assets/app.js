/*
 * Macrolab website - browser behaviour.
 *
 * The calendar mirrors the booking rules so the UI is pleasant to use, but the
 * server enforces them. Nothing here is a security control: every write is
 * re-checked server-side, including who owns the booking being changed.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        wireConfirmations(document);
        wireAutoSubmit();
        wireDateFields();
        wireInviteLink();
        wireDayPicker();
        wireDaySheet();
        initCalendar();
    });

    /*
     * Any element with data-confirm asks before submitting. Takes a root so
     * markup swapped in later - the time registration month list - can be
     * wired the same way.
     */
    function wireConfirmations(root) {
        root.querySelectorAll('[data-confirm]').forEach(function (el) {
            var handler = function (event) {
                if (!window.confirm(el.dataset.confirm)) {
                    event.preventDefault();
                }
            };

            if (el.tagName === 'FORM') {
                el.addEventListener('submit', handler);
            } else {
                el.addEventListener('click', handler);
            }
        });
    }

    /*
     * Pickers that submit their form as soon as the choice changes. The button
     * beside them is what works when this script has not run; the policy allows
     * no inline handler, so the wiring lives here.
     */
    function wireAutoSubmit() {
        document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (el.form) {
                    el.form.submit();
                }
            });
        });
    }

    /*
     * Date fields (date_field() in helpers.php): a text field that shows and
     * takes dates day first, 08-10-2026, plus a calendar button. The browser's
     * own date input would show the browser's order - month first in an
     * American one - so it only serves as the picker, out of sight. A field
     * may name an element (data-echo) to spell the date out in, with weekday.
     */
    function wireDateFields() {
        document.querySelectorAll('.date-field').forEach(function (wrap) {
            var text = wrap.querySelector('.date-text');
            var native = wrap.querySelector('.date-native');
            var button = wrap.querySelector('.date-pick');
            var echo = text.dataset.echo ? document.getElementById(text.dataset.echo) : null;

            var update = function () {
                var iso = toIso(text.value);

                /* Tidy what was typed (8/10/2026 becomes 08-10-2026), and let
                   the form refuse what is not a date at all. */
                if (iso) {
                    text.value = toDmy(iso);
                }
                text.setCustomValidity(text.value.trim() !== '' && !iso
                    ? 'Use day-month-year, for example 08-10-2026.' : '');
                native.value = iso || '';
                if (echo) {
                    echo.textContent = iso ? longDate(iso) : '';
                }
            };

            text.addEventListener('change', update);
            update();

            native.addEventListener('change', function () {
                text.value = toDmy(native.value);
                /* So the echo, auto-submit and the booking dialog all notice. */
                text.dispatchEvent(new Event('change', { bubbles: true }));
            });

            if (button && typeof native.showPicker === 'function') {
                button.hidden = false;
                button.addEventListener('click', function () {
                    native.value = toIso(text.value) || '';
                    try {
                        native.showPicker();
                    } catch (err) {
                        /* Refused, e.g. inside a cross-origin frame: type it. */
                        text.focus();
                    }
                });
            }
        });
    }

    /* Select the whole invite link on focus, so it can be copied in one go. */
    function wireInviteLink() {
        var field = document.querySelector('.invite-link');
        if (field) {
            field.addEventListener('focus', function () { field.select(); });
            field.focus();
        }
    }

    function initCalendar() {
        var el = document.getElementById('calendar');
        if (!el || typeof FullCalendar === 'undefined') {
            return;
        }

        var cfg = JSON.parse(el.dataset.config);
        var feedUrl = el.dataset.feed;
        var csrf = el.dataset.csrf;

        /* No equipment picked yet: an empty, read-only calendar that points to
           the picker when someone tries to book. */
        var hasEquipment = cfg.equipmentId !== null;
        var noticeEl = document.getElementById('calendar-notice');
        var equipmentEl = document.getElementById('equipment');
        var noticeTimer = null;

        var dialog = document.getElementById('booking-dialog');
        var form = document.getElementById('booking-form');
        var titleEl = document.getElementById('booking-dialog-title');
        var errorEl = document.getElementById('booking-dialog-error');
        var forEl = document.getElementById('booking-dialog-for');
        var start = whenField('start');
        var end = whenField('end');
        var purposeEl = document.getElementById('booking-purpose');
        var ownerNetidEl = document.getElementById('booking-owner');
        var saveBtn = document.getElementById('booking-save');
        var deleteBtn = document.getElementById('booking-delete');
        var closeBtn = document.getElementById('booking-close');

        /* The booking currently open in the dialog, or null when creating. */
        var editing = null;

        /*
         * A date input plus a time dropdown the application fills itself, so
         * the time always reads as 24h whatever the browser's locale is. The
         * date input keeps its native picker - its value is ISO either way -
         * and the echo beneath spells the date out day-first, so a widget
         * showing 09/14/2026 is never ambiguous.
         */
        function whenField(name) {
            /* The day-first text field of a date_field(); see wireDateFields(). */
            var dateEl = document.getElementById('booking-' + name + '-date');
            var pickEl = dateEl.closest('.date-field').querySelector('.date-pick');
            var timeEl = document.getElementById('booking-' + name + '-time');
            var echoEl = document.getElementById('booking-' + name + '-echo');

            fillTimes(timeEl, cfg.slotMinutes);

            var field = {
                date: dateEl,
                time: timeEl,
                /* The combined local value, as the API expects it: ISO. */
                value: function () {
                    var iso = toIso(dateEl.value);
                    return iso && timeEl.value ? iso + 'T' + timeEl.value : '';
                },
                set: function (date) {
                    dateEl.value = toDmy(isoDate(date));
                    dateEl.setCustomValidity('');
                    timeEl.value = nearestOption(timeEl, pad2(date.getHours()) + ':' + pad2(date.getMinutes()));
                    field.refresh();
                },
                refresh: function () {
                    var iso = toIso(dateEl.value);
                    echoEl.textContent = iso ? longDate(iso) : '';
                },
                readOnly: function (readOnly) {
                    dateEl.readOnly = readOnly;
                    timeEl.disabled = readOnly;
                    if (pickEl) { pickEl.disabled = readOnly; }
                }
            };

            dateEl.addEventListener('change', field.refresh);

            return field;
        }

        var calendar = new FullCalendar.Calendar(el, {
            initialView: 'timeGridWeek',
            /* 'local' rather than the named lab timezone: that would need an
               extra plugin, and the server already sends offsets. Bare times
               typed into the form are read by the server in the lab timezone. */
            timeZone: 'local',
            firstDay: 1,
            nowIndicator: true,
            allDaySlot: false,
            height: 'auto',
            slotDuration: minutes(cfg.slotMinutes),
            snapDuration: minutes(cfg.slotMinutes),
            slotMinTime: pad(cfg.openTime) + ':00',
            slotMaxTime: closingTime(cfg.closeTime),
            businessHours: {
                daysOfWeek: cfg.openDays,
                startTime: cfg.openTime,
                endTime: cfg.closeTime
            },
            /* Admins may need days the equipment is normally closed; everyone
               else is only shown the days they can actually book. */
            hiddenDays: cfg.isAdmin ? [] : hiddenDays(cfg.openDays),
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'timeGridWeek,timeGridDay,listWeek'
            },
            buttonText: { today: 'Today', week: 'Week', day: 'Day', list: 'List' },
            selectable: true,
            selectMirror: true,
            selectConstraint: cfg.isAdmin ? undefined : 'businessHours',
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            /* Dates are spelled out day first. The bundled library carries no
               locale data, so its own defaults would read as American; these
               callbacks decide the wording rather than the viewer's browser. */
            titleFormat: function (arg) { return rangeLabel(arg.start, arg.end); },
            dayHeaderFormat: function (arg) {
                return WEEKDAYS[arg.date.marker.getUTCDay()] + ' ' + arg.date.day + ' ' +
                    MONTHS[arg.date.month];
            },
            listDayFormat: function (arg) {
                return arg.date.day + ' ' + MONTHS[arg.date.month] + ' ' + arg.date.year;
            },
            events: hasEquipment ? loadEvents : [],
            select: function (info) {
                calendar.unselect();
                if (!hasEquipment) {
                    notice('Please choose the equipment first, then pick a time.');
                    if (equipmentEl) { equipmentEl.focus(); }
                    return;
                }
                openCreate(info.start, info.end);
            },
            eventClick: function (info) {
                openEvent(info.event);
            },
            eventDrop: function (info) { moveEvent(info); },
            eventResize: function (info) { moveEvent(info); }
        });

        calendar.render();

        /* A short message above the calendar that clears itself. */
        function notice(text) {
            if (!noticeEl) {
                return;
            }
            noticeEl.textContent = text;
            noticeEl.hidden = false;
            clearTimeout(noticeTimer);
            noticeTimer = setTimeout(function () { noticeEl.hidden = true; }, 6000);
        }

        /* ------------------------------------------------------------ data */

        function loadEvents(info, success, failure) {
            var url = feedUrl + '?equipment=' + encodeURIComponent(cfg.equipmentId) +
                '&from=' + encodeURIComponent(info.startStr) +
                '&to=' + encodeURIComponent(info.endStr);

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(readJson)
                .then(success)
                .catch(function (err) {
                    failure(err);
                    alert(err.message || 'The calendar could not be loaded.');
                });
        }

        function post(url, payload) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify(payload)
            }).then(readJson);
        }

        /* ---------------------------------------------------------- dialog */

        function openCreate(startsAt, endsAt) {
            editing = null;
            titleEl.textContent = 'Book the equipment';
            start.set(startsAt);
            end.set(endsAt);
            purposeEl.value = '';
            if (ownerNetidEl) { ownerNetidEl.value = ''; }
            /* The admin books for someone else, named by netID in the form. */
            forEl.textContent = cfg.isAdmin
                ? 'the person whose netID you enter below'
                : cfg.userLabel + ' (you)';
            deleteBtn.hidden = true;
            showError(null);
            open();
        }

        function openEvent(event) {
            var props = event.extendedProps || {};

            if (!props.canModify) {
                /* Someone else's booking: show who has the equipment, nothing more. */
                editing = null;
                titleEl.textContent = 'Booked';
                forEl.textContent = ownerText(props);
                start.set(event.start);
                end.set(event.end);
                purposeEl.value = '';
                setReadOnly(true);
                deleteBtn.hidden = true;
                saveBtn.hidden = true;
                showError(null);
                open();
                return;
            }

            editing = event;
            titleEl.textContent = props.own ? 'Your booking' : 'Booking for ' + props.owner;
            forEl.textContent = props.own ? cfg.userLabel + ' (you)' : ownerText(props);
            start.set(event.start);
            end.set(event.end);
            purposeEl.value = props.purpose || '';
            if (ownerNetidEl) { ownerNetidEl.value = ''; }
            setReadOnly(false);
            saveBtn.hidden = false;
            deleteBtn.hidden = false;
            showError(null);
            open();
        }

        /* Who a booking belongs to; the netID only when the server sends it. */
        function ownerText(props) {
            return props.owner + (props.ownerNetid ? ' (' + props.ownerNetid + ')' : '');
        }

        function open() {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        }

        function close() {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
            setReadOnly(false);
            saveBtn.hidden = false;
        }

        function setReadOnly(readOnly) {
            start.readOnly(readOnly);
            end.readOnly(readOnly);
            purposeEl.readOnly = readOnly;
            if (ownerNetidEl) { ownerNetidEl.disabled = readOnly; }
        }

        function showError(message) {
            errorEl.hidden = !message;
            errorEl.textContent = message || '';
        }

        closeBtn.addEventListener('click', close);
        dialog.addEventListener('cancel', function () { setReadOnly(false); saveBtn.hidden = false; });

        saveBtn.addEventListener('click', function () {
            if (!start.value() || !end.value()) {
                showError('Please give a start and an end time.');
                return;
            }

            var payload = {
                equipment: cfg.equipmentId,
                start: start.value(),
                end: end.value(),
                purpose: purposeEl.value
            };

            if (ownerNetidEl && ownerNetidEl.value) {
                payload.owner_netid = ownerNetidEl.value;
            }

            var url = editing ? feedUrl + '/' + editing.id : feedUrl;

            saveBtn.disabled = true;
            post(url, payload)
                .then(function () {
                    close();
                    calendar.refetchEvents();
                })
                .catch(function (err) { showError(err.message); })
                .finally(function () { saveBtn.disabled = false; });
        });

        deleteBtn.addEventListener('click', function () {
            if (!editing || !window.confirm('Cancel this booking?')) {
                return;
            }

            deleteBtn.disabled = true;
            post(feedUrl + '/' + editing.id + '/cancel', {})
                .then(function () {
                    close();
                    calendar.refetchEvents();
                })
                .catch(function (err) { showError(err.message); })
                .finally(function () { deleteBtn.disabled = false; });
        });

        /* Dragging or resizing writes straight away; a refusal snaps back. */
        function moveEvent(info) {
            post(feedUrl + '/' + info.event.id, {
                start: info.event.start.toISOString(),
                end: info.event.end.toISOString(),
                purpose: info.event.extendedProps.purpose || ''
            }).then(function () {
                calendar.refetchEvents();
            }).catch(function (err) {
                info.revert();
                alert(err.message);
            });
        }
    }

    /*
     * The printed day on the time sheet opens the browser's own date picker.
     * The input itself is kept out of sight rather than removed, because
     * showPicker() needs it rendered; where showPicker() does not exist the
     * input simply stays visible, as it is without this script.
     */
    function wireDayPicker() {
        var form = document.getElementById('day-nav');
        var input = document.getElementById('day-input');
        var button = document.getElementById('day-label');

        if (!form || !input || !button || typeof input.showPicker !== 'function') {
            return;
        }

        form.classList.add('has-picker');
        button.hidden = false;

        button.addEventListener('click', function () {
            try {
                input.showPicker();
            } catch (err) {
                /* Refused, e.g. inside a cross-origin frame: show the input. */
                form.classList.remove('has-picker');
                input.focus();
            }
        });
    }

    /*
     * The time registration day sheet. Each row is one project on one day and
     * saves itself when one of its cells is left after a change. The server
     * decides everything - rules, daily cap, ownership - and this only reports
     * what it said.
     */
    function wireDaySheet() {
        var table = document.getElementById('day-sheet');
        if (!table) {
            return;
        }

        var status = document.getElementById('day-sheet-status');
        var day = table.dataset.day;
        var csrf = table.dataset.csrf;
        var cellUrl = table.dataset.cellUrl;
        var monthUrl = table.dataset.monthUrl;

        table.querySelectorAll('tbody tr[data-project-id]').forEach(function (row) {
            var hours = row.querySelector('.day-hours');
            var note = row.querySelector('.day-note');

            if (!hours || !note || hours.disabled) {
                return;
            }

            /*
             * One request per row at a time. A change made while one is in
             * flight waits for it and is sent afterwards, so the server never
             * sees an older value land after a newer one.
             */
            var busy = false;
            var again = false;
            var changed = hours;

            function save() {
                if (busy) {
                    again = true;
                    return;
                }

                if (hours.value.trim() === '' && note.value.trim() !== '') {
                    markInvalid(hours, true);
                    say('Add hours for this remark, or clear the remark too to remove the row.', true);
                    return;
                }

                busy = true;
                say('Saving…', false);

                fetch(cellUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    /* Lets a save started by clicking an arrow outlive the page. */
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        day: day,
                        project_id: row.dataset.projectId,
                        hours: hours.value,
                        note: note.value
                    })
                })
                    .then(readJson)
                    .then(function (body) {
                        /* Show what was stored ("3,5" becomes "3:30"), unless
                           the cell has been changed again meanwhile. */
                        if (!again) {
                            hours.value = body.hours;
                            note.value = body.note;
                        }
                        markInvalid(hours, false);
                        markInvalid(note, false);
                        say('Saved. ' + body.dayTotal + ' logged on this day.', false);
                        refreshMonth();
                    })
                    .catch(function (err) {
                        markInvalid(changed, true);
                        say(err.message, true);
                    })
                    .finally(function () {
                        busy = false;
                        if (again) {
                            again = false;
                            save();
                        }
                    });
            }

            [hours, note].forEach(function (input) {
                input.addEventListener('change', function () {
                    changed = input;
                    save();
                });
            });
        });

        function say(message, isError) {
            status.textContent = message;
            status.classList.toggle('is-error', isError);
        }

        function markInvalid(input, invalid) {
            if (invalid) {
                input.setAttribute('aria-invalid', 'true');
            } else {
                input.removeAttribute('aria-invalid');
            }
        }

        /* Redraw the month list below so it agrees with what was saved. */
        function refreshMonth() {
            var section = document.getElementById('time-month');
            if (!section) {
                return;
            }

            var month = new URLSearchParams(window.location.search).get('month');
            var url = monthUrl + '?day=' + encodeURIComponent(day) +
                (month ? '&month=' + encodeURIComponent(month) : '');

            fetch(url, { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('The month list could not be refreshed.');
                    }
                    return response.text();
                })
                .then(function (html) {
                    var template = document.createElement('template');
                    template.innerHTML = html;
                    var fresh = template.content.getElementById('time-month');

                    if (fresh) {
                        wireConfirmations(fresh);
                        document.getElementById('time-month').replaceWith(fresh);
                    }
                })
                .catch(function () {
                    /* Not worth interrupting anyone for: the save itself
                       succeeded, and the list is right on the next load. */
                });
        }
    }

    /* ---------------------------------------------------------- helpers */

    /* A fetch response as JSON, or an Error carrying the server's message. */
    function readJson(response) {
        return response.json().then(function (body) {
            if (!response.ok) {
                var message = body && body.error ? body.error : 'Request failed.';
                throw new Error(message);
            }
            return body;
        }, function () {
            throw new Error(response.status === 401
                ? 'Your session has expired. Please reload the page and sign in again.'
                : 'The server sent an unreadable response.');
        });
    }

    function minutes(count) {
        var hours = Math.floor(count / 60);
        var rest = count % 60;
        return pad2(hours) + ':' + pad2(rest) + ':00';
    }

    function pad(time) { return /^\d:/.test(time) ? '0' + time : time; }

    function pad2(value) { return (value < 10 ? '0' : '') + value; }

    /* 24:00 is how FullCalendar spells "to the end of the day". */
    function closingTime(closeTime) {
        return closeTime === '00:00' ? '24:00:00' : pad(closeTime) + ':00';
    }

    function hiddenDays(openDays) {
        var hidden = [];
        for (var day = 0; day <= 6; day++) {
            /* openDays uses ISO numbering (Mon=1..Sun=7); FullCalendar uses
               Sun=0..Sat=6. */
            var iso = day === 0 ? 7 : day;
            if (openDays.indexOf(iso) === -1) {
                hidden.push(day);
            }
        }
        return hidden;
    }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /*
     * A calendar heading: "14 - 20 Sep 2026", collapsing whatever the two ends
     * have in common. FullCalendar hands the end as exclusive, so the last day
     * shown is the one before it.
     */
    function rangeLabel(startParts, endParts) {
        if (!endParts) {
            return startParts.day + ' ' + MONTHS[startParts.month] + ' ' + startParts.year;
        }

        var start = startParts.marker;
        /* The end is exclusive, and markers are UTC, so a day is always 24h. */
        var last = new Date(endParts.marker.getTime() - 86400000);

        var startDay = start.getUTCDate();
        var lastDay = last.getUTCDate();
        var startMonth = MONTHS[start.getUTCMonth()];
        var lastMonth = MONTHS[last.getUTCMonth()];
        var startYear = start.getUTCFullYear();
        var lastYear = last.getUTCFullYear();

        if (startYear !== lastYear) {
            return startDay + ' ' + startMonth + ' ' + startYear + ' - ' +
                lastDay + ' ' + lastMonth + ' ' + lastYear;
        }

        if (startMonth !== lastMonth) {
            return startDay + ' ' + startMonth + ' - ' + lastDay + ' ' + lastMonth + ' ' + lastYear;
        }

        if (startDay !== lastDay) {
            return startDay + ' - ' + lastDay + ' ' + lastMonth + ' ' + lastYear;
        }

        return startDay + ' ' + lastMonth + ' ' + lastYear;
    }

    /* A Date as the value a date input expects. */
    function isoDate(date) {
        return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    }

    /*
     * A date as typed - day first (08-10-2026, 8/10/2026, 8.10.2026) or ISO -
     * as "YYYY-MM-DD", or null when it is not a real calendar date. The server
     * reads dates the same way (Clock::isoDate()).
     */
    function toIso(text) {
        var value = (text || '').trim();
        var m;
        var day, month, year;

        if ((m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(value))) {
            year = Number(m[1]); month = Number(m[2]); day = Number(m[3]);
        } else if ((m = /^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/.exec(value))) {
            day = Number(m[1]); month = Number(m[2]); year = Number(m[3]);
        } else {
            return null;
        }

        var date = new Date(year, month - 1, day);
        if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
            return null;
        }

        return year + '-' + pad2(month) + '-' + pad2(day);
    }

    /* "2026-10-08" as the site shows dates: "08-10-2026". */
    function toDmy(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
    }

    /*
     * An ISO date spelled out day-first. The browser may render the date input
     * itself in any order it likes; this is what the reader goes by.
     */
    function longDate(iso) {
        var parts = iso.split('-');
        var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

        return WEEKDAYS[date.getDay()] + ' ' + Number(parts[2]) + ' ' +
            MONTHS[Number(parts[1]) - 1] + ' ' + parts[0];
    }

    /* Fill a dropdown with every time of day, at the booking slot's spacing. */
    function fillTimes(select, stepMinutes) {
        var step = Math.max(1, Math.min(24 * 60, stepMinutes || 30));
        var options = document.createDocumentFragment();

        for (var m = 0; m < 24 * 60; m += step) {
            var option = document.createElement('option');
            option.value = pad2(Math.floor(m / 60)) + ':' + pad2(m % 60);
            option.textContent = option.value;
            options.appendChild(option);
        }

        select.replaceChildren(options);
    }

    /*
     * The option closest to a wanted time. A booking made before the admin
     * changed the slot length may not land on the current grid, and the server
     * would rather hear a valid time than an empty one.
     */
    function nearestOption(select, wanted) {
        var target = toMinutes(wanted);
        var best = null;
        var bestGap = Infinity;

        Array.prototype.forEach.call(select.options, function (option) {
            var gap = Math.abs(toMinutes(option.value) - target);
            if (gap < bestGap) {
                bestGap = gap;
                best = option.value;
            }
        });

        return best === null ? wanted : best;
    }

    function toMinutes(time) {
        var parts = time.split(':');

        return Number(parts[0]) * 60 + Number(parts[1]);
    }
})();
