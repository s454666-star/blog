"""Generate public/css/tw-stock-dark-pages.css from the inline <style> blocks of the tw-stock views.

Every page was written for a light background and hard-codes many colours. Rather than hand-port
hundreds of rules, this script re-emits each colour-bearing declaration under
`:where(:root[data-sx-theme="dark"][data-sx-route="<route>"])` with the colour remapped by lightness:
light surfaces become deep navy surfaces of the same hue, dark ink becomes light ink, and
saturated accents are lifted so they stay legible. Shared rules in tw-stock-experience.css load
after this file and win any specificity tie.

Re-run after changing a page's styles:  python scripts/build-tw-stock-dark-css.py
"""
import colorsys
import io
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VIEWS = os.path.join(ROOT, 'resources', 'views', 'tw-stock')
OUT = os.path.join(ROOT, 'public', 'css', 'tw-stock-dark-pages.css')

PAGES = {
    'tw-stock.financial-reports.index': 'financial-reports.blade.php',
    'tw-stock.annual-comparison.index': 'annual-comparison.blade.php',
    'tw-stock.daily-prices.index': 'daily-prices/index.blade.php',
    'tw-stock.daily-prices.show': 'daily-prices/show.blade.php',
    'tw-stock.institutional-flows.index': 'institutional-flows.blade.php',
    'tw-stock.upcoming-dividends.index': 'upcoming-dividends.blade.php',
    'tw-stock.monthly-revenues.index': 'monthly-revenue-rankings.blade.php',
    'tw-stock.eps-growth-rankings.index': 'eps-growth-rankings.blade.php',
    'tw-stock.active-etf-operations.index': 'active-etf-operations.blade.php',
    'tw-stock.taiex-index.kline': 'taiex-index-kline.blade.php',
}

COLOR_PROPS = {
    'color': 'text', 'fill': 'text', 'stroke': 'text', 'caret-color': 'text',
    'text-decoration-color': 'text', 'outline-color': 'line',
    'background': 'bg', 'background-color': 'bg', 'background-image': 'bg',
    'border': 'line', 'border-color': 'line', 'border-top': 'line', 'border-bottom': 'line',
    'border-left': 'line', 'border-right': 'line', 'border-top-color': 'line',
    'border-bottom-color': 'line', 'border-left-color': 'line', 'border-right-color': 'line',
    'box-shadow': 'shadow',
}

COLOR_RE = re.compile(r'#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b|rgba?\([^)]*\)|\bwhite\b')


def parse_color(token):
    token = token.strip()
    if token == 'white':
        return 1.0, 1.0, 1.0, 1.0
    if token.startswith('#'):
        h = token[1:]
        if len(h) == 3:
            h = ''.join(c * 2 for c in h)
        return int(h[0:2], 16) / 255, int(h[2:4], 16) / 255, int(h[4:6], 16) / 255, 1.0
    parts = [p.strip() for p in token[token.index('(') + 1:-1].replace('/', ',').split(',')]
    r, g, b = (float(p) / 255 for p in parts[:3])
    a = float(parts[3]) if len(parts) > 3 else 1.0
    return r, g, b, a


def fmt(r, g, b, a):
    r, g, b = (max(0, min(255, round(c * 255))) for c in (r, g, b))
    if a >= 0.999:
        return '#{:02x}{:02x}{:02x}'.format(r, g, b)
    return 'rgba({}, {}, {}, {})'.format(r, g, b, round(a, 3))


def remap(token, role):
    r, g, b, a = parse_color(token)
    h, l, s = colorsys.rgb_to_hls(r, g, b)
    if role == 'shadow':
        # Light-theme shadows are tinted ink; on dark they become plain black depth.
        return fmt(0, 0, 0, min(0.6, a * 2.2)) if l < 0.5 else fmt(r, g, b, a * 0.25)
    if role == 'text':
        if l < 0.3 or (s < 0.3 and l < 0.6):   # ink (incl. navy/slate) → light ink
            l2 = 0.93 - l * 0.45
            s2 = min(0.45, max(s, 0.25))
            h2 = h if s > 0.05 else 0.6
            return fmt(*colorsys.hls_to_rgb(h2, l2, s2), a)
        if l < 0.66:                      # saturated accent → lifted accent
            return fmt(*colorsys.hls_to_rgb(h, max(0.66, l + 0.18), min(1, s * 1.05)), a)
        return token
    if role == 'bg':
        if l >= 0.62:                     # light surface → deep surface, same hue
            hue = h if s > 0.06 else 0.6
            sat = max(0.35, min(0.55, s)) if s > 0.06 else 0.45
            l2 = 0.10 + (1 - l) * 0.55
            return fmt(*colorsys.hls_to_rgb(hue, min(0.24, l2), sat), a)
        return token
    if role == 'line':
        if l >= 0.6:
            hue = h if s > 0.06 else 0.6
            l2 = 0.2 + (1 - l) * 0.35
            return fmt(*colorsys.hls_to_rgb(hue, min(0.36, l2), 0.42), a)
        if s < 0.25 and l < 0.35:         # dark neutral border (e.g. active buttons) → visible line
            return fmt(*colorsys.hls_to_rgb(0.6, 0.32, 0.4), a)
        return token
    return token


def custom_prop_role(name, value):
    colors = COLOR_RE.findall(value)
    if not colors:
        return None
    _, l, _ = colorsys.rgb_to_hls(*parse_color(colors[0])[:3])
    if any(k in name for k in ('bg', 'panel', 'surface', 'background')):
        return 'bg'
    if any(k in name for k in ('line', 'border')):
        return 'line'
    return 'bg' if l > 0.8 else 'text'


def transform_value(value, role):
    return COLOR_RE.sub(lambda m: remap(m.group(0), role), value)


def strip_blade(css):
    css = re.sub(r'@include\([^)]*\)', '', css)
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    return css


def parse_blocks(css):
    """Yield (prelude, body) pairs; nested at-rules are returned as ('@media ...', [children])."""
    i, n = 0, len(css)
    out = []
    while i < n:
        j = css.find('{', i)
        if j == -1:
            break
        prelude = css[i:j].strip()
        depth, k = 1, j + 1
        while k < n and depth:
            if css[k] == '{':
                depth += 1
            elif css[k] == '}':
                depth -= 1
            k += 1
        body = css[j + 1:k - 1]
        if prelude.startswith('@media') or prelude.startswith('@supports') or prelude.startswith('@container'):
            out.append((prelude, parse_blocks(body)))
        elif not prelude.startswith('@'):
            out.append((prelude, body))
        i = k
    return out


def convert_rule(selector, body, scope):
    decls = []
    for decl in body.split(';'):
        if ':' not in decl:
            continue
        prop, value = decl.split(':', 1)
        prop, value = prop.strip().lower(), value.strip()
        important = '!important' in value
        if prop.startswith('--'):
            role = custom_prop_role(prop, value)
        else:
            role = COLOR_PROPS.get(prop)
        if role is None or not COLOR_RE.search(value):
            continue
        new = transform_value(value, role)
        # Unchanged colours are re-emitted too, so the generated file preserves the page's own
        # cascade order (otherwise a remapped earlier rule could beat an untouched later one).
        decls.append('{}: {}{}'.format(prop, new.replace('!important', '').strip(), ' !important' if important else ''))
    if not decls:
        return None
    selectors = []
    for sel in selector.split(','):
        sel = sel.strip()
        if not sel or sel.startswith(':root') or sel in ('html', 'body', '*') or sel.startswith('body::') or sel.startswith('html'):
            continue
        sel = re.sub(r'^body\b', 'body.tw-stock-experience', sel)
        selectors.append('{} {}'.format(scope, sel))
    if not selectors:
        return None
    return '{} {{ {}; }}'.format(',\n'.join(selectors), '; '.join(decls))


def convert_blocks(blocks, scope):
    lines = []
    for prelude, body in blocks:
        if isinstance(body, list):
            inner = convert_blocks(body, scope)
            if inner:
                lines.append('{} {{\n{}\n}}'.format(prelude, '\n'.join('    ' + l.replace('\n', '\n    ') for l in inner)))
        else:
            rule = convert_rule(prelude, body, scope)
            if rule:
                lines.append(rule)
    return lines


def main():
    chunks = [
        '/* GENERATED by scripts/build-tw-stock-dark-css.py — do not edit by hand. */',
        '/* Per-page dark-theme colour remap; tw-stock-experience.css loads after this file. */',
    ]
    for route, view in PAGES.items():
        src = io.open(os.path.join(VIEWS, view), encoding='utf-8').read()
        styles = re.findall(r'<style>(.*?)</style>', src, flags=re.S)
        # :where() keeps the scope at zero specificity: generated rules tie with the page's own rule
        # (and win by load order) while the shared theme's more specific rules still win over them.
        scope = ':where(:root[data-sx-theme="dark"][data-sx-route="{}"])'.format(route)
        lines = []
        for css in styles:
            lines.extend(convert_blocks(parse_blocks(strip_blade(css)), scope))
        chunks.append('\n/* ---- {} ({}) ---- */'.format(route, view))
        chunks.extend(lines)
    io.open(OUT, 'w', encoding='utf-8', newline='\n').write('\n'.join(chunks) + '\n')
    print('wrote', OUT)


if __name__ == '__main__':
    main()
