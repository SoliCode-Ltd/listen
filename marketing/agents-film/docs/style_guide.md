# Style guide: the agents film

Taken from the reference the owner supplied (a two-minute product launch film, studied frame by
frame; `docs/reference/cuts.json` has its rhythm). The motion and composition are the
reference's; the colour, the face and everything said are Listen's own.

## What the reference does

- **Three grounds, and nothing else:** a soft flowing colour field (silk, slanted, slow), a
  plain off-white, a near black. Scenes cut hard between them.
- **Small, light type in the middle of the frame.** A sentence waits in grey and each word turns
  dark as it is reached. Nothing bounces, nothing slides in from the side.
- **White cards on the colour** for what people say: a small label (a dot and a name), then the
  words as they are typed or spoken, the newest word still grey. The agent's card is glass.
- **An orb beside the conversation**, on its own white panel: the same colour seen through a
  sphere.
- **A circle that opens onto the colour**, with a line of words inside it.
- **Names passing** on the colour (languages there; dialects here).
- **Pace:** median shot about 3 seconds; conversations are held for 10 seconds and more. Calm.

## How it is done here

| | |
|---|---|
| Colour | Listen's violet for the film's own scenes; each agent's scene takes one of the orb palettes an owner can choose (`AgentWidget::PALETTES`): violet, emerald, ocean, rose |
| Face | Alexandria, weights 400 to 600; headings 46 to 64 px, card text 34 px, labels 22 to 25 px (on 1920 × 1080) |
| Motion | fades and small rises (12 to 22 px), ease out; one slow drift on every colour field |
| Words | the agent's words appear at the moment each is spoken (timed from the recording, word by word); a caller's are typed at about 26 letters a second |
| Voices | real recorded calls from the homepage (`public/audio/agents/calls`), one agent line each |
| Sound | a quiet 90 bpm bed made in code, soft ticks for typing, a bell for what is saved; the music ducks under each voice |
| Grain | a fine grain over every frame, so the soft colour never bands |

## Never

- Bold, bouncing type; dark cards on a flat ground (the first test: rejected).
- White words over a pale part of the colour: behind a conversation the field keeps to its
  deeper half, and the agent's glass is smoked.
- Any other company's name, mark or frames.
