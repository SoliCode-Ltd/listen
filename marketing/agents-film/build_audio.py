#!/usr/bin/env python3
"""Lays the film's four calls on its timeline.

Run from marketing/agents-film once the voices are made (the "Launch ad voices" workflow,
ad: agents-film):

    python3 build_audio.py

Reads voices/<slug>.wav and voices/words.json (each word's moment in its clip), and writes
audio/vo.wav (every line at its place), audio/vo.json (the voice's loudness 60 times a second,
each word's moment in the film, and where every scene starts and ends, in beats) and the film's
length in film.json. The score is then made for that length (beats.py, see the end of the run).
"""
import json, math, pathlib, struct, subprocess

HERE = pathlib.Path(__file__).parent
BPM = 90
P = 60 / BPM
ORDER = ['eg', 'sa', 'kw', 'lb']
# What the screen writes for each line: the script, with its numbers as numbers.
SHOWN = {
    'af-eg-ask': 'مساء الخير، شحنتي كان المفروض توصل النهارده. هي فين دلوقتي؟',
    'af-eg-agent': 'مساء النور يا فندم. شحنتك خرجت مع المندوب من ساعة، وهتوصل لحضرتك قبل الساعة 6. تحب أبعتلك رابط التتبع؟',
    'af-sa-ask': 'السلام عليكم، أبي أحجز تجربة قيادة يوم السبت.',
    'af-sa-agent': 'وعليكم السلام، حياك الله. عندنا موعد السبت الساعة 5 العصر، يناسبك؟ بس أحتاج اسمك الكريم ورقم جوالك.',
    'af-kw-ask': 'هلا، أبي أحجز غرفة حق شخصين، من الخميس لين السبت.',
    'af-kw-agent': 'هلا والله، حياك. عندنا غرفة تطل على البحر بهالتاريخ، بـ 45 دينار بالليلة. أأكد لك الحجز؟',
    'af-lb-ask': 'مرحبا، بدّي آخد موعد شوف الشقة يلّي بالأشرفية، بكرا إذا بيصير.',
    'af-lb-agent': 'أهلا وسهلا فيكي. أكيد، بكرا الساعة 11 الصبح منيح؟ وبس بدّي اسمك ورقمك لأكّد الموعد.',
}

words = json.loads((HERE / 'voices/words.json').read_text(encoding='utf-8'))


def dur(slug):
    out = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', str(HERE / f'voices/{slug}.wav')], capture_output=True, text=True).stdout
    return float(out.strip())


def timed(slug, at):
    """Each shown word with its moment in the film: the heard word of the same place in the line."""
    shown = SHOWN[slug].split()
    heard = [w['start'] for w in words[slug]['words']]
    if not heard:
        heard = [0.0]
    out = []
    for i, w in enumerate(shown):
        j = i if len(heard) == len(shown) else round(i * (len(heard) - 1) / max(1, len(shown) - 1))
        out.append([w, round(at + heard[min(j, len(heard) - 1)], 3)])
    # never two words on the same instant (a number said as two words, written as one)
    for i in range(1, len(out)):
        if out[i][1] <= out[i - 1][1]:
            out[i][1] = round(out[i - 1][1] + 0.12, 3)
    return out


calls, clips, u = [], [], 14
scenes = {}
for i, key in enumerate(ORDER):
    ask, agent = f'af-{key}-ask', f'af-{key}-agent'
    t0 = u * P
    ask_at = t0 + (1.6 if i == 0 else 0.9)
    agent_at = ask_at + dur(ask) + 0.45
    end = agent_at + dur(agent)
    u1 = math.ceil((end + 0.9) / P)
    calls.append({'key': key, 'u0': u, 'u1': u1,
                  'ask': {'at': round(ask_at, 3), 'end': round(ask_at + dur(ask), 3), 'words': timed(ask, ask_at)},
                  'agent': {'at': round(agent_at, 3), 'end': round(end, 3), 'words': timed(agent, agent_at)}})
    clips += [(ask, ask_at), (agent, agent_at)]
    u = u1
    if i == 0:
        scenes['dialects'] = [u, u + 5]
        u += 5
scenes['saved'] = [u, u + 7]; u += 7
scenes['try'] = [u, u + 6]; u += 6
scenes['logo'] = [u, u + 5]; u += 5
total = u
length = round(total * P, 3)

# every line at its place, each at the same loudness
inputs, graph, names = [], [], []
for n, (slug, at) in enumerate(clips):
    inputs += ['-i', str(HERE / f'voices/{slug}.wav')]
    graph.append(f'[{n}]loudnorm=I=-16:TP=-1.5,adelay={round(at * 1000)}:all=1[a{n}]')
    names.append(f'[a{n}]')
graph.append(''.join(names) + f'amix=inputs={len(clips)}:normalize=0,apad=whole_dur={length}')
(HERE / 'audio').mkdir(exist_ok=True)
subprocess.run(['ffmpeg', '-v', 'error', '-y', *inputs, '-filter_complex', ';'.join(graph), '-t', str(length), '-ar', '48000', '-ac', '2', str(HERE / 'audio/vo.wav')], check=True)

# its loudness, 60 times a second
raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', str(HERE / 'audio/vo.wav'), '-ac', '1', '-ar', '16000', '-f', 's16le', '-'], capture_output=True).stdout
pcm = struct.unpack('<%dh' % (len(raw) // 2), raw)
hop = 16000 // 60
env = [math.sqrt(sum(x * x for x in pcm[i:i + hop * 2]) / max(1, len(pcm[i:i + hop * 2]))) / 32768 for i in range(0, len(pcm), hop)]
top = sorted(env)[int(len(env) * 0.995)] or 1
env = [round(min(1, e / top), 3) for e in env]

(HERE / 'audio/vo.json').write_text(json.dumps({'fps': 60, 'bpm': BPM, 'beats': total, 'env': env, 'calls': calls, 'scenes': scenes}, ensure_ascii=False), encoding='utf-8')
film = json.loads((HERE / 'film.json').read_text())
film['duration'] = length
film['poster'] = round(calls[1]['agent']['at'] + 2, 2)
(HERE / 'film.json').write_text(json.dumps(film, indent=1))

print(f'{total} beats, {length} s')
for c in calls:
    print(f"  {c['key']}: beats {c['u0']}-{c['u1']}, asks at {c['ask']['at']}, answers {c['agent']['at']}-{c['agent']['end']} ({len(c['ask']['words'])}+{len(c['agent']['words'])} words)")
print('  ' + ', '.join(f'{k} {a}-{b}' for k, (a, b) in scenes.items()))
print(f'next: beats.py agents-film --synth --flavor lofi --bpm {BPM} --mode major --tonic D --drop 1 --duration {length} --seed 11')
