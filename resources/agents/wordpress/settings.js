/*
 * Listen Voice Agent: the list of pages on its settings page. It adds the search, "Select all",
 * "Clear" and the live count; without it, the list is a plain list of checkboxes that still saves.
 * Plain browser JavaScript, no libraries. It writes only text, never HTML.
 */
(function () {
	'use strict';

	var picker = document.querySelector('[data-lva-picker]');
	var search = picker && picker.querySelector('[data-lva-search]');
	if (!search) {
		return;
	}

	var words = read(picker.getAttribute('data-lva-words'));
	var counter = picker.querySelector('[data-lva-count]');
	var empty = picker.querySelector('[data-lva-empty]');
	var groups = toArray(picker.querySelectorAll('[data-lva-group]'));
	var rows = toArray(picker.querySelectorAll('[data-lva-row]')).map(function (row) {
		return { row: row, box: row.querySelector('input[type="checkbox"]'), text: loose(row.textContent) };
	});
	var countWords = plural(words.counts || {}, words.lang || 'en');

	function read(json) {
		try {
			return JSON.parse(json || '{}') || {};
		} catch (error) {
			return {};
		}
	}

	function toArray(list) {
		return Array.prototype.slice.call(list);
	}

	// Text as the search compares it: any case, without accents or Arabic short vowels, with
	// Arabic and Persian digits as 0-9, and the Arabic letters people type more than one way
	// (أ إ آ ٱ and ا, ة and ه, ى and ي) as one.
	function loose(text) {
		return String(text).toLowerCase().normalize('NFKD')
			.replace(/[̀-ًͯ-ٰٟـ]/g, '')
			.replace(/[٠-٩]/g, function (digit) { return String(digit.charCodeAt(0) - 0x0660); })
			.replace(/[۰-۹]/g, function (digit) { return String(digit.charCodeAt(0) - 0x06f0); })
			.replace(/ٱ/g, 'ا')
			.replace(/ة/g, 'ه')
			.replace(/ى/g, 'ي')
			.replace(/\s+/g, ' ')
			.trim();
	}

	// The count in words, for any number: the page gives the words for sample numbers, and the
	// browser's plural rules for the dashboard's language say which sample each number goes with.
	function plural(samples, lang) {
		var rules = null;
		try {
			rules = new Intl.PluralRules(lang);
		} catch (error) {
			rules = null;
		}
		function kind(count) {
			return rules ? rules.select(count) : (count === 1 ? 'one' : 'other');
		}
		var forms = {};
		Object.keys(samples).forEach(function (count) {
			var form = kind(Number(count));
			if (!(form in forms)) {
				forms[form] = samples[count];
			}
		});

		return function (count) {
			var form = forms[kind(count)] || forms.other || '%d';

			return form.replace('%d', String(count));
		};
	}

	function count() {
		var ticked = rows.filter(function (item) { return item.box.checked; }).length;
		counter.textContent = ticked === 0 ? (words.none || '0') : countWords(ticked);
		counter.classList.toggle('is-empty', ticked === 0);
	}

	function filter() {
		var terms = loose(search.value).split(' ').filter(Boolean);
		var shown = 0;
		rows.forEach(function (item) {
			var match = terms.every(function (term) { return item.text.indexOf(term) !== -1; });
			item.row.hidden = !match;
			shown += match ? 1 : 0;
		});
		groups.forEach(function (group) {
			group.hidden = !group.querySelector('[data-lva-row]:not([hidden])');
		});
		var typed = search.value.trim();
		empty.hidden = shown > 0;
		empty.textContent = shown > 0 ? '' : (words.noMatch || '%s').replace('%s', function () { return typed; });
	}

	search.addEventListener('input', filter);
	search.addEventListener('search', filter);
	search.addEventListener('keydown', function (event) {
		// Enter would send the form from here: the search only narrows the list.
		if (event.key === 'Enter') {
			event.preventDefault();
		}
		if (event.key === 'Escape' && search.value !== '') {
			event.preventDefault();
			search.value = '';
			filter();
		}
	});

	picker.addEventListener('change', function (event) {
		if (event.target && event.target.type === 'checkbox') {
			count();
		}
	});

	// "Select all" ticks the pages the search shows; "Clear" unticks every page.
	picker.querySelector('[data-lva-all]').addEventListener('click', function () {
		rows.forEach(function (item) {
			if (!item.row.hidden) {
				item.box.checked = true;
			}
		});
		count();
	});
	picker.querySelector('[data-lva-clear]').addEventListener('click', function () {
		rows.forEach(function (item) {
			item.box.checked = false;
		});
		count();
	});

	// Browsers without :has() show the list whatever is picked; this shows it only with
	// "Only on the pages I choose", as the style does elsewhere.
	if (!(window.CSS && CSS.supports && CSS.supports('selector(:has(*))'))) {
		var choices = toArray(document.querySelectorAll('[data-lva-placement]'));
		var place = function () {
			picker.hidden = choices.some(function (choice) {
				return choice.checked && choice.getAttribute('data-lva-placement') === 'Everywhere';
			});
		};
		choices.forEach(function (choice) {
			choice.addEventListener('change', place);
		});
		place();
	}

	count();
}());
