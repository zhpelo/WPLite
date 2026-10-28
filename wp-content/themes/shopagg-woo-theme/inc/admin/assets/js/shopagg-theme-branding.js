(function () {
	'use strict';

	const replacements = [
		[/SHOPAGG Theme Core/g, 'SHOPAGG 主题核心'],
		[/WoodMart Core/g, 'SHOPAGG 主题核心'],
		[/Woodmart Core/g, 'SHOPAGG 主题核心'],
		[/WOODMART CORE/g, 'SHOPAGG 主题核心'],
		[/WoodMart dashboard/g, 'SHOPAGG主题后台'],
		[/Woodmart dashboard/g, 'SHOPAGG主题后台'],
		[/WoodMart/g, 'SHOPAGG主题'],
		[/Woodmart/g, 'SHOPAGG主题'],
		[/WOODMART/g, 'SHOPAGG主题'],
		[/XTemos Studio/g, 'SHOPAGG主题'],
		[/Xtemos Studio/g, 'SHOPAGG主题'],
		[/XTemos/g, 'SHOPAGG主题'],
		[/Xtemos/g, 'SHOPAGG主题'],
		[/XTEMOS/g, 'SHOPAGG主题'],
	];
	const skippedTags = new Set(['SCRIPT', 'STYLE', 'CODE', 'PRE', 'TEXTAREA']);
	const visibleAttributes = ['title', 'alt', 'aria-label', 'placeholder'];

	function replaceBranding(value) {
		return replacements.reduce(function (result, replacement) {
			return result.replace(replacement[0], replacement[1]);
		}, value);
	}

	function isEditable(node) {
		const parent = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
		return Boolean(parent && parent.closest('[contenteditable="true"]'));
	}

	function processTextNode(node) {
		if (!node.nodeValue || !node.parentElement || skippedTags.has(node.parentElement.tagName) || isEditable(node)) {
			return;
		}

		const brandedValue = replaceBranding(node.nodeValue);
		if (brandedValue !== node.nodeValue) {
			node.nodeValue = brandedValue;
		}
	}

	function processElement(element) {
		if (!(element instanceof Element) || skippedTags.has(element.tagName) || isEditable(element)) {
			return;
		}

		visibleAttributes.forEach(function (attribute) {
			if (!element.hasAttribute(attribute)) {
				return;
			}

			const value = element.getAttribute(attribute);
			const brandedValue = replaceBranding(value);
			if (brandedValue !== value) {
				element.setAttribute(attribute, brandedValue);
			}
		});

		const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
		let textNode;
		while ((textNode = walker.nextNode())) {
			processTextNode(textNode);
		}
	}

	function initialize() {
		document.title = replaceBranding(document.title);
		processElement(document.body);

		const observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				if (mutation.type === 'characterData') {
					processTextNode(mutation.target);
					return;
				}

				mutation.addedNodes.forEach(function (node) {
					if (node.nodeType === Node.TEXT_NODE) {
						processTextNode(node);
					} else if (node.nodeType === Node.ELEMENT_NODE) {
						processElement(node);
					}
				});
			});
		});

		observer.observe(document.body, {
			childList: true,
			characterData: true,
			subtree: true,
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}
})();
