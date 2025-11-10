const onReady = (fn) => {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn, { once: true });
  } else {
    fn();
  }
};

const roomsCache = new Map();
const toastIds = new Set();

function sanitizeText(text) {
  return (text ?? '')
    .toString()
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatRelativeTime(isoString) {
  if (!isoString) return '';
  const now = new Date();
  const ts = new Date(isoString);
  if (Number.isNaN(ts.getTime())) return '';
  const diff = (ts.getTime() - now.getTime()) / 1000; // seconds
  const abs = Math.abs(diff);
  const units = [
    { step: 60, name: 'second' },
    { step: 60, name: 'minute' },
    { step: 24, name: 'hour' },
    { step: 7, name: 'day' },
    { step: 4.34524, name: 'week' },
    { step: 12, name: 'month' },
    { step: Infinity, name: 'year' },
  ];
  let delta = abs;
  let unit = 'second';
  for (const u of units) {
    if (delta < u.step) {
      unit = u.name;
      break;
    }
    delta /= u.step;
  }
  const value = Math.max(1, Math.round(delta));
  const label = value === 1 ? unit : `${unit}s`;
  return diff < 0 ? `${value} ${label} ago` : `in ${value} ${label}`;
}

function initNav() {
  const toggle = document.getElementById('navToggle');
  const panel = document.getElementById('navPanel');
  const body = document.body;
  if (!toggle || !panel) return;

  const mq = window.matchMedia('(min-width: 980px)');
  const isDesktop = () => mq.matches;

  const syncAria = () => {
    if (isDesktop()) {
      panel.classList.remove('open');
      panel.removeAttribute('aria-hidden');
      toggle.classList.remove('is-active');
      toggle.setAttribute('aria-expanded', 'false');
      body.classList.remove('nav-open');
    } else {
      panel.setAttribute('aria-hidden', panel.classList.contains('open') ? 'false' : 'true');
    }
  };

  const open = () => {
    panel.classList.add('open');
    toggle.classList.add('is-active');
    toggle.setAttribute('aria-expanded', 'true');
    if (!isDesktop()) {
      panel.setAttribute('aria-hidden', 'false');
      body.classList.add('nav-open');
    }
  };

  const close = () => {
    panel.classList.remove('open');
    toggle.classList.remove('is-active');
    toggle.setAttribute('aria-expanded', 'false');
    if (!isDesktop()) {
      panel.setAttribute('aria-hidden', 'true');
    }
    body.classList.remove('nav-open');
  };

  toggle.addEventListener('click', (event) => {
    event.stopPropagation();
    if (panel.classList.contains('open')) {
      close();
    } else {
      open();
    }
  });

  document.addEventListener('click', (event) => {
    if (!panel.classList.contains('open')) return;
    if (panel.contains(event.target) || toggle.contains(event.target)) return;
    close();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && panel.classList.contains('open')) {
      close();
    }
  });

  panel.addEventListener('click', (event) => {
    if (isDesktop()) return;
    const link = event.target.closest('a');
    if (link) close();
  });

  const handleMatchChange = (event) => {
    if (event.matches) close();
    syncAria();
  };

  if (mq.addEventListener) {
    mq.addEventListener('change', handleMatchChange);
  } else if (mq.addListener) {
    mq.addListener(handleMatchChange);
  }

  syncAria();
}

async function fetchRoomsForBuilding(buildingId) {
  const key = String(buildingId || '');
  if (!key || key === '0') return [];
  if (roomsCache.has(key)) {
    return roomsCache.get(key);
  }
  try {
    const resp = await fetch(`/rooms.php?action=by_building&id=${encodeURIComponent(key)}`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    });
    if (!resp.ok) throw new Error('Failed to fetch rooms');
    const data = await resp.json();
    if (Array.isArray(data)) {
      roomsCache.set(key, data);
      return data;
    }
  } catch (err) {
    console.warn('Room lookup failed', err);
  }
  roomsCache.set(key, []);
  return [];
}

function initRooms() {
  const sources = document.querySelectorAll('[data-room-source]');
  if (!sources.length) return;

  const ensurePlaceholder = (target) => {
    if (!target || target.dataset.roomPlaceholder) return;
    const first = target.querySelector('option[value=""]');
    if (first) target.dataset.roomPlaceholder = first.textContent.trim();
  };

  const populateSelect = (target, rooms) => {
    if (!target) return;
    ensurePlaceholder(target);
    const placeholder = target.dataset.roomPlaceholder || 'Select room';
    const current = target.value;
    target.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = placeholder;
    target.appendChild(defaultOption);
    let found = false;
    rooms.forEach((room) => {
      const option = document.createElement('option');
      option.value = String(room.id);
      option.textContent = room.label || room.room_number || `Room ${room.id}`;
      if (String(room.id) === current) {
        option.selected = true;
        found = true;
      }
      target.appendChild(option);
    });
    if (!found) {
      target.value = '';
    }
  };

  const populateDatalist = (datalist, rooms) => {
    if (!datalist) return;
    datalist.innerHTML = '';
    rooms.forEach((room) => {
      const opt = document.createElement('option');
      opt.value = room.room_number || room.label || '';
      opt.label = room.label || opt.value;
      datalist.appendChild(opt);
    });
  };

  const validateInput = (input, rooms, buildingId) => {
    if (!input) return;
    const trimmed = input.value.trim();
    if (!buildingId) {
      input.setCustomValidity(trimmed ? 'Choose a building first.' : '');
      return;
    }
    if (!trimmed) {
      input.setCustomValidity('');
      return;
    }
    const exists = rooms.some((room) => {
      const number = (room.room_number || '').toLowerCase();
      return number && number === trimmed.toLowerCase();
    });
    input.setCustomValidity(exists ? '' : 'Room not found for this building.');
  };

  sources.forEach((select) => {
    const targetId = select.dataset.roomTarget;
    const inputId = select.dataset.roomInput;
    const datalistId = select.dataset.roomDatalist;
    const target = targetId ? document.getElementById(targetId) : null;
    const input = inputId ? document.getElementById(inputId) : null;
    const datalist = datalistId ? document.getElementById(datalistId) : null;

    ensurePlaceholder(target);

    const refresh = async () => {
      const buildingId = select.value;
      if (!buildingId) {
        populateSelect(target, []);
        if (datalist) datalist.innerHTML = '';
        if (input) input.setCustomValidity('');
        return;
      }
      const rooms = await fetchRoomsForBuilding(buildingId);
      populateSelect(target, rooms);
      populateDatalist(datalist, rooms);
      validateInput(input, rooms, buildingId);
    };

    select.addEventListener('change', refresh);

    if (input) {
      input.addEventListener('blur', async () => {
        const buildingId = select.value;
        if (!buildingId) {
          validateInput(input, [], '');
          return;
        }
        const rooms = await fetchRoomsForBuilding(buildingId);
        validateInput(input, rooms, buildingId);
      });
      input.addEventListener('input', () => {
        input.setCustomValidity('');
      });
    }

    if (select.value) {
      refresh();
    }
  });
}

function createToast(item) {
  const stack = document.getElementById('toastStack');
  if (!stack) return null;
  const id = `toast-${item.id || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
  if (toastIds.has(id)) return null;
  toastIds.add(id);

  const toast = document.createElement('article');
  toast.className = 'toast';
  toast.dataset.variant = item.variant || 'info';
  toast.dataset.toastId = id;

  const title = sanitizeText(item.title) || 'Notification';
  const bodyHtml = item.body ? sanitizeText(item.body).replace(/\n/g, '<br>') : '';
  const url = item.url ? sanitizeText(item.url) : '';
  const time = sanitizeText(item.created_at);
  const parsedDate = time ? new Date(time) : null;
  const hasValidDate = parsedDate && !Number.isNaN(parsedDate.getTime());
  const relative = hasValidDate ? formatRelativeTime(time) : '';
  const absolute = hasValidDate ? parsedDate.toLocaleString() : '';

  toast.innerHTML = `
    <button class="toast__close" type="button" aria-label="Dismiss notification">&times;</button>
    <div class="toast__title">${title}</div>
    ${bodyHtml ? `<div class="toast__body">${bodyHtml}</div>` : ''}
    ${(relative || absolute) ? `<div class="toast__meta">${relative ? `<span>${relative}</span>` : ''}${absolute ? `<time datetime="${time}">${absolute}</time>` : ''}</div>` : ''}
    ${url ? `<div class="toast__actions"><a href="${url}">Open</a></div>` : ''}
  `;

  const closeBtn = toast.querySelector('.toast__close');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => dismissToast(toast));
  }

  stack.appendChild(toast);
  setTimeout(() => dismissToast(toast), 8000);
  return toast;
}

function dismissToast(toast) {
  if (!toast) return;
  const id = toast.dataset.toastId;
  toast.classList.add('is-leaving');
  setTimeout(() => {
    toast.remove();
    if (id) toastIds.delete(id);
  }, 180);
}

function initNotifications() {
  const dot = document.getElementById('notifDot');
  const body = document.body;
  if (!body || !dot) return;

  const streamUrl = body.dataset.notifStream;
  const pollUrl = body.dataset.notifPoll;

  const renderCount = (count) => {
    const num = Number(count) || 0;
    if (num > 0) {
      dot.textContent = num > 99 ? '99+' : String(num);
      dot.classList.add('is-visible');
    } else {
      dot.textContent = '';
      dot.classList.remove('is-visible');
    }
    window.dispatchEvent(new CustomEvent('notifications:count', { detail: { count: num } }));
  };

  const showToasts = (items = []) => {
    items.forEach((item) => createToast(item));
  };

  if (streamUrl && 'EventSource' in window) {
    let es;
    const connect = () => {
      es = new EventSource(streamUrl);
      es.addEventListener('count', (event) => {
        try {
          const data = JSON.parse(event.data || '{}');
          renderCount(data.count);
        } catch (err) {
          console.warn('Failed to parse count event', err);
        }
      });
      es.addEventListener('notifications', (event) => {
        try {
          const data = JSON.parse(event.data || '{}');
          if (Array.isArray(data.items)) {
            showToasts(data.items);
          }
        } catch (err) {
          console.warn('Failed to parse notifications event', err);
        }
      });
      es.onerror = () => {
        es.close();
        setTimeout(connect, 5000);
      };
    };
    connect();
    return;
  }

  // Fallback polling for older browsers
  if (pollUrl) {
    const poll = async () => {
      try {
        const resp = await fetch(pollUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        if (resp.ok) {
          const json = await resp.json();
          if (json && typeof json.count !== 'undefined') {
            renderCount(json.count);
          }
        }
      } catch (err) {
        console.warn('Notification poll failed', err);
      } finally {
        setTimeout(poll, 30000);
      }
    };
    poll();
  }
}

function initCommandPalette() {
  const palette = document.getElementById('commandPalette');
  const input = document.getElementById('commandPaletteInput');
  const results = document.getElementById('commandPaletteResults');
  if (!palette || !input || !results) return;

  const openButtons = document.querySelectorAll('[data-command-open]');
  const body = document.body;
  let visibleCommands = [];
  let activeIndex = 0;

  const baseCommands = (() => {
    const commands = [];
    const navLinks = document.querySelectorAll('.nav a.nav__link');
    navLinks.forEach((link) => {
      const label = link.textContent.trim();
      if (!label) return;
      commands.push({
        label,
        url: link.getAttribute('href'),
        description: 'Navigate',
        group: 'Navigation',
      });
    });

    commands.push(
      { label: 'Create Task', url: '/task_new.php', description: 'Draft a new task', group: 'Actions', shortcut: 'N' },
      { label: 'View Tasks', url: '/tasks.php', description: 'Open task list', group: 'Actions' },
      { label: 'Rooms Directory', url: '/rooms.php', description: 'Manage rooms and buildings', group: 'Data' },
      { label: 'Inventory', url: '/inventory.php', description: 'Open inventory overview', group: 'Data' },
      { label: 'Profile & Devices', url: '/account/profile.php', description: 'Manage account and notifications', group: 'Account' },
      { label: 'Notification Inbox', url: '/notifications/index.php', description: 'Review all alerts', group: 'Account' },
    );

    return commands;
  })();

  const closePalette = () => {
    if (palette.hasAttribute('hidden')) return;
    palette.dataset.state = 'closed';
    palette.setAttribute('hidden', '');
    body.classList.remove('command-open');
    results.innerHTML = '';
    input.value = '';
    visibleCommands = [];
    activeIndex = 0;
  };

  const openPalette = () => {
    if (!palette.hasAttribute('hidden')) return;
    palette.removeAttribute('hidden');
    palette.dataset.state = 'open';
    body.classList.add('command-open');
    input.value = '';
    filterCommands('');
    setTimeout(() => input.focus(), 0);
  };

  const activateItem = (index) => {
    const items = results.querySelectorAll('.command-palette__item');
    items.forEach((item) => item.setAttribute('aria-selected', 'false'));
    if (!items.length) return;
    const clamped = Math.max(0, Math.min(index, items.length - 1));
    const current = items[clamped];
    if (current) {
      current.setAttribute('aria-selected', 'true');
      current.scrollIntoView({ block: 'nearest' });
      activeIndex = clamped;
    }
  };

  const executeCommand = (command) => {
    if (!command) return;
    closePalette();
    if (command.action === 'open-task' && command.url) {
      window.location.href = command.url;
      return;
    }
    if (command.url) {
      window.location.href = command.url;
    }
    if (typeof command.handler === 'function') {
      command.handler();
    }
  };

  const renderCommands = (commands) => {
    visibleCommands = commands;
    results.innerHTML = '';
    if (!commands.length) {
      const empty = document.createElement('li');
      empty.className = 'command-palette__item';
      empty.setAttribute('role', 'option');
      empty.setAttribute('aria-disabled', 'true');
      empty.textContent = 'No matches found. Try broader terms or #ID.';
      results.appendChild(empty);
      activeIndex = 0;
      return;
    }

    let currentGroup = null;
    commands.forEach((cmd, idx) => {
      if (cmd.group && cmd.group !== currentGroup) {
        currentGroup = cmd.group;
        const groupLi = document.createElement('li');
        groupLi.className = 'command-palette__group';
        groupLi.textContent = currentGroup;
        groupLi.setAttribute('role', 'presentation');
        results.appendChild(groupLi);
      }
      const li = document.createElement('li');
      li.className = 'command-palette__item';
      li.setAttribute('role', 'option');
      li.dataset.index = String(idx);
      const metaParts = [];
      if (cmd.description) metaParts.push(`<span>${cmd.description}</span>`);
      if (cmd.shortcut) metaParts.push(`<span>${cmd.shortcut}</span>`);
      const meta = metaParts.length ? `<span class="command-palette__item-meta">${metaParts.join('')}</span>` : '';
      li.innerHTML = `
        <span class="command-palette__item-label">${cmd.label}</span>
        ${meta}
      `;
      li.addEventListener('click', () => {
        const position = Number(li.dataset.index);
        executeCommand(visibleCommands[position]);
      });
      results.appendChild(li);
    });

    activeIndex = 0;
    activateItem(activeIndex);
  };

  const buildSpecialCommands = (query) => {
    const specials = [];
    const trimmed = query.trim();
    const taskMatch = trimmed.match(/^#?(\d{1,8})$/);
    if (taskMatch) {
      const id = taskMatch[1];
      specials.push({
        label: `Open Task #${id}`,
        url: `/task_view.php?id=${id}`,
        description: 'Jump directly to task details',
        group: 'Shortcuts',
        action: 'open-task',
      });
    }
    return specials;
  };

  const filterCommands = (query) => {
    const normalized = query.trim().toLowerCase();
    const specials = buildSpecialCommands(normalized);
    if (!normalized) {
      renderCommands([...specials, ...baseCommands]);
      return;
    }
    const matches = baseCommands.filter((cmd) => {
      const haystack = [cmd.label, cmd.description, cmd.group]
        .filter(Boolean)
        .join(' ') 
        .toLowerCase();
      return haystack.includes(normalized);
    });
    renderCommands([...specials, ...matches]);
  };

  input.addEventListener('input', () => filterCommands(input.value));

  openButtons.forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      openPalette();
    });
  });

  palette.addEventListener('click', (event) => {
    if (event.target.closest('[data-command-close]')) {
      closePalette();
    }
  });

  document.addEventListener('keydown', (event) => {
    if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
      event.preventDefault();
      if (palette.hasAttribute('hidden')) {
        openPalette();
      } else {
        closePalette();
      }
    }
  });

  input.addEventListener('keydown', (event) => {
    const items = results.querySelectorAll('.command-palette__item');
    if (!items.length) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activateItem(Math.min(activeIndex + 1, items.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      activateItem(Math.max(activeIndex - 1, 0));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const el = items[activeIndex];
      if (el) {
        const index = Number(el.dataset.index);
        executeCommand(visibleCommands[index]);
      }
    } else if (event.key === 'Escape') {
      event.preventDefault();
      closePalette();
    }
  });

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePalette();
    }
  });
}

onReady(() => {
  initNav();
  initNotifications();
  initRooms();
  initCommandPalette();
});