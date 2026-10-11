import sys, re
for p in sys.argv[1:]:
    lines = open(p, encoding='utf-8').read().split('\n')
    prose = tags = 0
    for l in lines:
        st = l.strip()
        if not (st.startswith(('*', '/*', '//')) or st == '*/'):
            continue
        body = re.sub(r'^(/\*\*?|\*/|\*|//)\s?', '', st).strip()
        if not body or set(body) <= set('─-='):
            continue
        if body.startswith('@'):
            tags += 1
        else:
            prose += 1
    n = len(lines)
    print(f"{p.split('/')[-1]:<24} {n:>4} Zeilen | Prosa {prose:>3} ({prose*100//n:>2}%) | Tags {tags}")
