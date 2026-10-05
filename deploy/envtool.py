#!/usr/bin/env python3
"""
Production backend/.env from GitHub instead of a hand-edited file on the server.

The template is backend/.env.production.example: it lists every key and its default. A GitHub
Secret or Variable (environment `production`) with the same name overrides that default.
Secrets hold what must stay hidden (keys, passwords, tokens, webhooks); Variables hold the
rest (APP_URL, FEATURE_*, ...) and can be read and edited in the GitHub UI.

  render   (in the Deploy workflow) template + SECRETS_JSON + VARS_JSON -> .env file
  push     (on your machine, needs `gh auth login`) values -> GitHub Secrets / Variables

  python3 deploy/envtool.py render --out /tmp/backend.env
  python3 deploy/envtool.py push                      # asks for the required values
  python3 deploy/envtool.py push --from server.env    # copies an existing .env (e.g. the server's)

Only keys in the template are written (plus the ones listed in the EXTRA_ENV_KEYS variable),
so nothing else from the GitHub context ends up in the file. Docs: docs/DEPLOY.md.
"""
import argparse
import getpass
import json
import os
import re
import secrets
import base64
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TEMPLATE = os.path.join(ROOT, 'backend', '.env.production.example')
LINE = re.compile(r'^([A-Z][A-Z0-9_]*)=(.*)$')

# Must be set (not empty, not a REPLACE placeholder) for a production deploy.
REQUIRED = [
    'APP_KEY', 'APP_URL', 'SHOPIFY_API_KEY', 'SHOPIFY_API_SECRET',
    'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'HORIZON_BASIC_AUTH_USER', 'HORIZON_BASIC_AUTH_PASSWORD',
    'MAIL_HOST', 'MAIL_FROM_ADDRESS', 'SUPPORT_EMAIL', 'WEBSITE_URL', 'FRONTEND_URL',
]
# Template defaults that are only placeholders for these keys.
WEAK = {'DB_PASSWORD': {'secret'}, 'DB_ROOT_PASSWORD': {'root'}}
# Kept visible as Variables even at their default, so they can be switched from the GitHub UI.
ALWAYS_VARIABLE = re.compile(r'^(FEATURE_.*|BILLING_GROWTH_OFFERED|LOG_LEVEL)$')
SECRET = re.compile(r'(^APP_KEY$|^APP_PREVIOUS_KEYS$|_SECRET$|PASSWORD$|TOKEN$|WEBHOOK_URL$|USERNAME$|_USER$)')
# Values written without quotes; anything else is single-quoted (no interpolation in Laravel or compose).
BARE = re.compile(r'^[A-Za-z0-9_./:@,+=%-]*$')


def is_secret(key):
    return bool(SECRET.search(key))


def unquote(raw):
    raw = raw.strip()
    if len(raw) >= 2 and raw[0] == raw[-1] and raw[0] in '"\'':
        return raw[1:-1]
    return raw


def parse(path):
    """KEY -> value of an .env file (comments and blank lines skipped)."""
    out = {}
    with open(path, encoding='utf-8') as f:
        for line in f:
            m = LINE.match(line.rstrip('\n'))
            if m:
                out[m.group(1)] = unquote(m.group(2))
    return out


def fmt(value):
    if BARE.match(value):
        return value
    if "'" in value:
        raise ValueError("contains both a single quote and characters that need quoting")
    if '\n' in value:
        raise ValueError('contains a line break')
    return f"'{value}'"


def problem(key, value):
    """Why a required key's value can't be used, or None."""
    if value in ('', 'null') or 'REPLACE_WITH' in value:
        return f'{key} is not set'
    if value in WEAK.get(key, set()):
        return f'{key} still has the example value "{value}"'
    return None


def problems(values):
    return [p for p in (problem(k, values.get(k, '')) for k in REQUIRED) if p]


def render(args):
    found = {}
    for name in ('VARS_JSON', 'SECRETS_JSON'):  # secrets win over variables of the same name
        raw = os.environ.get(name) or '{}'
        found[name] = {k: v for k, v in json.loads(raw).items() if isinstance(v, str)}
    given = {**found['VARS_JSON'], **found['SECRETS_JSON']}
    both = sorted(set(found['VARS_JSON']) & set(found['SECRETS_JSON']))
    for key in both:
        print(f'::warning::{key} is both a Secret and a Variable; the Secret is used.')

    if 'APP_KEY' not in given:
        # Not set up yet: keep the hand-made backend/.env on the server.
        print('APP_KEY is not a GitHub Secret: .env is not managed by CI, the server keeps its own file.')
        write_output('managed', 'false')
        return 0

    with open(args.template, encoding='utf-8') as f:
        lines = f.read().splitlines()

    extra = [k.strip() for k in given.get('EXTRA_ENV_KEYS', '').split(',') if k.strip()]
    template_keys = {m.group(1) for m in (LINE.match(l) for l in lines) if m}
    values, out, errors = {}, [], []
    for line in lines:
        m = LINE.match(line)
        if not m:
            out.append(line)
            continue
        key = m.group(1)
        if key in given:
            try:
                out.append(f'{key}={fmt(given[key])}')
            except ValueError as e:
                errors.append(f'{key} {e}')
            values[key] = given[key]
        else:
            out.append(line)
            values[key] = unquote(m.group(2))

    added = [k for k in extra if k not in template_keys]
    if added:
        out += ['', '# --- Extra keys (EXTRA_ENV_KEYS) ---']
        for key in added:
            if not LINE.match(f'{key}='):
                errors.append(f'EXTRA_ENV_KEYS: "{key}" is not a valid name')
            elif key not in given:
                errors.append(f'EXTRA_ENV_KEYS lists {key} but there is no Secret or Variable {key}')
            else:
                try:
                    out.append(f'{key}={fmt(given[key])}')
                except ValueError as e:
                    errors.append(f'{key} {e}')

    errors += problems(values)
    if errors:
        for e in errors:
            print(f'::error::{e}')
        print('Set them in GitHub: Settings -> Environments -> production (or: python3 deploy/envtool.py push).')
        return 1

    old = os.umask(0o077)
    try:
        with open(args.out, 'w', encoding='utf-8') as f:
            f.write('# Generated by the Deploy workflow from GitHub Secrets/Variables (deploy/envtool.py).\n')
            f.write('# Do not edit on the server: change the Secret/Variable and redeploy.\n')
            f.write('\n'.join(out) + '\n')
    finally:
        os.umask(old)

    overridden = sorted(k for k in given if k in values or k in added)
    print(f'backend/.env rendered: {len(values) + len(added)} keys, {len(overridden)} from GitHub '
          f'({sum(1 for k in overridden if k in found["SECRETS_JSON"])} secrets).')
    for key in overridden:
        source = 'secret' if key in found['SECRETS_JSON'] else f'variable = {given[key]}'
        print(f'  {key}: {source}')
    write_output('managed', 'true')
    return 0


def write_output(name, value):
    path = os.environ.get('GITHUB_OUTPUT')
    if path:
        with open(path, 'a', encoding='utf-8') as f:
            f.write(f'{name}={value}\n')


def gh(args, body=None):
    return subprocess.run(['gh', *args], input=body, text=True, capture_output=True)


def push(args):
    if gh(['auth', 'status']).returncode != 0:
        print('Log in to GitHub first: gh auth login', file=sys.stderr)
        return 1
    template = parse(args.template)
    source = parse(args.source) if args.source else {}
    env = args.environment

    plan = {}  # key -> value to store
    for key, default in template.items():
        if key in source:
            value = source[key]
            if value != default or key in REQUIRED or ALWAYS_VARIABLE.match(key):
                plan[key] = value
        elif ALWAYS_VARIABLE.match(key):
            plan[key] = default

    if 'APP_KEY' not in plan or plan['APP_KEY'] == '':
        if args.source:
            print('!! The file has no APP_KEY. Use the one the server already runs with: a new key makes '
                  'stored Shopify tokens unreadable (to change it, see docs/DEPLOY.md "Đổi APP_KEY").', file=sys.stderr)
            return 1
        plan['APP_KEY'] = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
        print('APP_KEY: generated a new key (first setup only; change it later only as in docs/DEPLOY.md "Đổi APP_KEY").')

    # Ask for required values that are still missing (hidden input for secrets).
    for key in REQUIRED:
        value = plan.get(key, template.get(key, ''))
        while problem(key, value):
            print(f'  {problem(key, value)}' + (f' in {args.source}' if args.source else ''))
            value = (getpass.getpass(f'{key}: ') if is_secret(key) else input(f'{key}: ')).strip()
        plan[key] = value

    errors = problems({k: plan.get(k, '') for k in REQUIRED})
    if errors:
        print('\n'.join(errors), file=sys.stderr)
        return 1

    print(f'\nEnvironment "{env}" of the current repository:')
    for key in sorted(plan):
        print(f'  {"secret  " if is_secret(key) else "variable"} {key}' + ('' if is_secret(key) else f' = {plan[key]}'))
    if not args.yes and input('Write these? [y/N] ').strip().lower() != 'y':
        return 1

    failed = 0
    for key in sorted(plan):
        if is_secret(key):
            r = gh(['secret', 'set', key, '--env', env], body=plan[key])  # value via stdin, not argv
        else:
            r = gh(['variable', 'set', key, '--env', env, '--body', plan[key]])
        if r.returncode != 0:
            failed += 1
            print(f'!! {key}: {r.stderr.strip()}', file=sys.stderr)
    print(f'Done: {len(plan) - failed} written, {failed} failed. Next deploy writes backend/.env on the server.')
    return 1 if failed else 0


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest='cmd', required=True)
    r = sub.add_parser('render', help='build backend/.env from SECRETS_JSON / VARS_JSON (Deploy workflow)')
    r.add_argument('--template', default=TEMPLATE)
    r.add_argument('--out', required=True)
    s = sub.add_parser('push', help='store values as GitHub Secrets / Variables (needs gh auth login)')
    s.add_argument('--template', default=TEMPLATE)
    s.add_argument('--from', dest='source', help='an existing .env to copy (e.g. the server backend/.env)')
    s.add_argument('--environment', default='production')
    s.add_argument('--yes', action='store_true', help='do not ask for confirmation')
    args = p.parse_args()
    return render(args) if args.cmd == 'render' else push(args)


if __name__ == '__main__':
    sys.exit(main())
