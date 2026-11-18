# Dice Roller

A small PHP dice roller for tabletop games. It parses D&D-style dice expressions and shows the
breakdown for every roll, so you can check a result instead of trusting the total.

**Live:** https://songtianxiang.com/dice/

## Expressions it understands

| Expression | Meaning |
| --- | --- |
| `1d20` | one twenty-sided die |
| `d20` | same, the count defaults to 1 |
| `4d6kh3` | roll 4d6, keep the highest 3 (ability scores) |
| `2d20kl1` | roll 2d20, keep the lowest (disadvantage) |
| `2d6+3` | modifier arithmetic |
| `1d8+1d6-1` | mixed dice and modifiers in one expression |

## Running locally

Requires PHP 8.0+ — no dependencies.

```bash
php -S 127.0.0.1:8000
```

Then open <http://127.0.0.1:8000/>.

## Structure

- `index.php` — parser, roller, and the whole page (single file on purpose: it is a one-page tool)

## License

MIT — see [LICENSE](LICENSE).
