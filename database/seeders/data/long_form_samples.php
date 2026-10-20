<?php

/*
 * The voice samples page's long recordings: whole texts made in one go, as a
 * Studio Max member makes them, each shown with how long it took to make.
 * The texts are written for the page; every one stays within the longest
 * text Studio Max takes at once on live (8,000 characters).
 *
 * Made and timed on the site by the "Long-form samples record" workflow
 * (scripts/tts/long_form_record.php), through the studio's own Generate
 * request, with the site's keys and its usual traffic. It commits:
 *
 *   public/audio/long-form/<slug>.mp3         the recording, for the page
 *   database/seeders/data/long_form_takes.json  what was measured
 *
 * A take is shown only while its text is the one below (the take keeps the
 * text's sha1): editing a text hides its card until it is made again.
 *
 * voice: the voice's internal id (it must be on sale); style: a studio
 * performance style; kind: ui.samples.long_kind_<kind>.
 */

return [
    'samples' => [

        // ── English ─────────────────────────────────────────────────

        ['slug' => 'long-en-clockmaker', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'en-Gemini-HD-Sulafat',
            'style' => 'storytelling', 'kind' => 'story',
            'title' => 'The Clockmaker of Harrow Lane',
            'text' => <<<'TEXT'
On the corner of Harrow Lane, between a bakery and a shop that sold nothing but string, there was a narrow door with a brass bell above it. Behind that door, for forty-one years, Elias Brandt had mended clocks.

He was not a famous clockmaker. No museum had ever asked for his work, and no newspaper had ever printed his name. But everyone on Harrow Lane knew the sound of his bell, and most of them, at one time or another, had climbed the two worn steps into his shop with something wrapped in a tea towel and an apologetic look on their face.

"It stopped," they would say. "It was my grandmother's. I know it isn't worth much."

And Elias would unwrap the tea towel very slowly, as if there might be a bird inside, and he would say, "Let me see what it wants."

That was how he talked about clocks. Not what was wrong with them, but what they wanted. A clock that ran fast wanted to be calmed. A clock that ran slow wanted cleaning, or a little oil, or simply a better place on the wall, away from the draught by the window. A clock that had stopped altogether, he said, usually wanted someone to listen to it properly for the first time in years.

The shop itself was hardly bigger than a wardrobe. There was a bench under the window, a lamp with a green shade, a hundred tiny drawers with handwritten labels, and on every wall, from the skirting board to the ceiling, clocks. Long clocks and round clocks, carriage clocks and cuckoo clocks, a ship's clock with a brass rim, and a small black clock shaped like a cat whose eyes moved from side to side with every second. Each one was set to the right time, and each one ticked in its own voice, so that standing in the middle of the shop was like standing in the middle of a very polite rainstorm.

Children loved it. They pressed their faces to the window to watch the cat's eyes, and on the hour, when every clock in the shop struck at once, they jumped and laughed and ran off down the lane to tell their friends.

Elias did not have children of his own. His wife, Marta, had died eleven winters ago, and since then he had lived alone in the two rooms above the shop. He did not complain about it. He had his work, his tea, and his clocks, and in the evenings he read detective stories by the window until the lamps came on along the lane.

But there was one clock he had never been able to mend.

It stood on a shelf at the back of the shop, behind the bench, where customers rarely looked. It was a plain wooden mantel clock with a cream face and thin black hands, and it had stopped at twenty past four. Marta had bought it at a market on the coast the summer they were married. She had carried it home on her lap on the train, and for thirty years it had stood on the shelf above their kitchen table, ticking quietly while they ate breakfast and argued about the crossword.

It had stopped on the afternoon she died. Elias had not noticed at first. It was only days later, sitting alone at the kitchen table, that he looked up and saw the hands standing still at twenty past four, and understood that the house had been silent for longer than he had realised.

He had taken it down to the shop that same evening. He had opened the back, cleaned every wheel, checked every pin and every tooth. There was nothing wrong with it. The spring was sound, the pendulum was true. He wound it, set it going, and it ticked for a minute, perhaps two, and then it stopped again. At twenty past four.

He tried again the next week, and the week after that. He replaced the spring, though it did not need replacing. He took the whole movement apart and laid the pieces on a white cloth like a puzzle, and put them back together with tweezers and a magnifying glass, and still it stopped. After a year, he stopped trying. He put it on the shelf at the back of the shop and left it there, and the hands stayed at twenty past four, and in time he got used to it, the way a person gets used to a stone in their shoe.

Then, one wet Tuesday in November, a girl came into the shop.

She was perhaps nine or ten, in a yellow raincoat that was too big for her, and she was carrying a shoebox held together with an elastic band. She put it on the counter and stood very straight, the way children do when they have practised what they are going to say.

"My name is Nell," she said. "I live above the bakery. This is my alarm clock and it doesn't ring anymore, and I have to get up for school, and my mum says it's not worth mending, but I have saved four pounds and twenty pence."

She put the coins on the counter in a neat pile.

Elias opened the shoebox. Inside was a small round alarm clock, bright red, with two bells on top and a cartoon rabbit painted on the face. It was the cheapest kind of clock there was. Any other clockmaker would have told her to buy a new one.

"Let me see what it wants," said Elias.

He showed her how to open the back. He let her hold the screwdriver. He explained what the hammer did, and why the bells were different sizes, and how the little spring inside had slipped off its hook, the way a bicycle chain slips off its gear. Nell watched with her chin on her hands and asked a question every ten seconds, and Elias, who had not explained anything to anyone in a very long time, found that he did not mind at all.

When it was done, he set the alarm for one minute ahead, and they waited. The rabbit's hands crept round. The whole shop seemed to hold its breath. And then the alarm went off, so loud and so sudden that Nell shrieked and Elias dropped his screwdriver, and the two of them laughed until the clocks on the wall seemed to laugh with them.

He would not take her four pounds and twenty pence. She would not leave without paying. In the end they agreed that she would come back on Saturday and sweep the shop floor, and that would make them even.

She came on Saturday. And the Saturday after that. By Christmas she knew the name of every clock on the wall, and by spring she could take apart a carriage clock and put it back together, with only a little help and only one spare screw.

It was on a warm afternoon in April that she found the mantel clock at the back of the shop.

"Why is this one stopped?" she asked.

Elias was quiet for a long moment. Then he told her. About Marta, and the market by the sea, and the train, and the kitchen table, and twenty past four. He had never told anyone the whole story before. When he had finished, Nell did not say anything. She just looked at the clock for a while, and then she asked if she could try.

He almost said no. Then he handed her the key.

She did not open the back. She did not reach for the screwdriver. Instead, she picked up the clock very carefully, carried it to the front of the shop, and set it on the windowsill, in the sun, where Marta used to sit and read while he worked. Then she wound it, and nudged the pendulum, and stepped back.

It ticked.

It ticked past twenty past four, and past half past, and past five. It ticked while the bakery closed and the lamps came on along the lane. It was still ticking when Nell went home for her tea, and it was ticking the next morning when Elias came down the stairs in his slippers, and stood in the doorway, and listened.

He never did find out why it worked. Perhaps the shelf at the back had never been quite level. Perhaps the shop had been too cold, or too quiet. Or perhaps, he thought, some clocks simply want to be somewhere they can see the street, and the people going by, and the light.

The mantel clock still stands in the window on Harrow Lane. If you go there on a Saturday, you will find a girl in a yellow raincoat sweeping the floor, and an old man at the bench under the green lamp, and on the windowsill, in the sun, a plain wooden clock with a cream face, keeping perfect time.
TEXT],

        ['slug' => 'long-en-deep-sea', 'language' => 'en', 'dialect' => 'en-GB', 'voice' => 'en-Gemini-HD-Charon',
            'style' => 'documentary', 'kind' => 'documentary',
            'title' => 'Into the Midnight Zone',
            'text' => <<<'TEXT'
Two hundred metres below the surface of the ocean, the last of the sunlight fades away. The water turns from blue to violet, from violet to grey, and then, quite suddenly, to black. This is where the deep sea begins. It covers more than half of our planet, and we have seen less of it than we have seen of the surface of the Moon.

For most of human history, people believed that nothing could live down here. The pressure is crushing. At four thousand metres, every square centimetre of a body carries the weight of a small car. The temperature hovers just above freezing. There are no plants, because there is no light for them to grow. It seemed obvious that the deep ocean must be a desert, empty and still.

It is anything but.

In the eighteen seventies, a ship called the Challenger set out on a voyage around the world to find out what lay beneath the waves. Its crew lowered nets and dredges on ropes several kilometres long, and slowly, painfully, they hauled up the answer. Starfish. Sponges. Worms. Strange fish with enormous mouths and tiny, useless eyes. In four years, they described nearly five thousand species that no one had ever seen before. The desert, it turned out, was full of life.

Since then, we have learned to go and look for ourselves. Submersibles with thick steel hulls and windows of curved acrylic carry scientists down into the dark. Robots on long cables film the sea floor in high definition. And what they find, again and again, is that the deep ocean is not just alive. It is glowing.

Switch off the lights of a submersible in the midnight zone, and wait. After a few moments, the darkness begins to sparkle. A flash of blue here. A trail of green there. A slow pulse of light drifting past the window like a lantern on a string. Down here, where the sun never reaches, around three quarters of all animals can make their own light.

They use it for everything. Some fish carry a glowing lure on a stalk above their heads, and wait, perfectly still, for something curious to swim close enough to eat. Some shrimp, when attacked, spit out a cloud of shining liquid, and slip away while the predator snaps at the light. Many animals have rows of tiny lights along their bellies, tuned to match the faint glow coming down from above, so that a hunter looking up from below sees nothing at all. It is camouflage made of light.

The deep sea also holds some of the strangest families on Earth. On the slopes of underwater volcanoes, hot water bursts from cracks in the rock at more than three hundred and fifty degrees, black with minerals. It should be deadly. Instead, it is surrounded by life. Giant tube worms, taller than a person, sway in the warm water, their tips a brilliant red. Pale crabs crawl between them. White shrimp crowd so thickly around the vents that the rock disappears beneath them.

None of these animals depend on the sun. They live on the chemistry of the planet itself. Bacteria turn the minerals in the hot water into food, and everything else lives on the bacteria. When these vents were discovered, in nineteen seventy seven, they changed the way scientists thought about life. If life can thrive here, in the dark, on the heat of the Earth, then perhaps it can thrive in other dark places too: in the hidden oceans beneath the ice of distant moons.

Much of the deep sea still lives on something far simpler. Every day, a slow snowfall drifts down from the bright water above. Scraps of plankton, the shells of tiny animals, flakes of dead fish. Scientists call it marine snow. It can take weeks to reach the bottom, and by the time it arrives, there is not much left. So the animals of the abyss have learned to wait. Many grow slowly and live for a very long time. Some deep sea corals are thousands of years old. A shark that lives in the cold waters of the far north may reach four hundred years of age, swimming through the dark while centuries pass above it.

And sometimes, a feast falls from the sky. When a whale dies, its body sinks, and settles on the sea floor. Within hours, the first scavengers arrive. Hagfish and sleeper sharks strip the flesh. Crabs and worms move in after them. Over the years that follow, a whole community grows on the bones, and when they are finally gone, the sea floor quietly returns to waiting.

We are only beginning to understand this world. Every expedition into the deep still finds animals that have never been named. Some of them may hold answers to questions we have not yet thought to ask: about how life began, about how it survives, about how much of our own planet we have still to explore.

So the next time you stand on a beach and look out at the water, remember what lies beneath it. Below the waves, below the light, there is a vast and ancient world, cold and dark and crushing, and full of tiny lights, moving slowly through the night.
TEXT],

        // ── Arabic ──────────────────────────────────────────────────

        ['slug' => 'long-ar-calligrapher', 'language' => 'ar', 'dialect' => 'ar-XA', 'voice' => 'ar-Gemini-HD-Sulafat',
            'style' => 'storytelling', 'kind' => 'story',
            'title' => 'خطّاط الحيّ القديم',
            'text' => <<<'TEXT'
في آخر زقاق ضيّق من أزقة المدينة القديمة، حيث تتعانق البيوت حتى تكاد شرفاتها تتلامس، كان هناك دكّان صغير بباب خشبي أزرق، فوقه لوحة كُتب عليها بخطّ جميل: «الخطّاط».

لم يكن في الدكّان ما يلفت نظر المارّة. طاولة منخفضة، وسجّادة قديمة، ومحابر من الزجاج والنحاس، وحزم من أقلام القصب مربوطة بخيوط ملوّنة، وأوراق مصفوفة بعناية على رفوف من خشب الجوز. وفي وسط هذا كلّه، كان يجلس رجل في السبعين من عمره، أبيض اللحية، هادئ الملامح، اسمه الشيخ يوسف.

قضى الشيخ يوسف أكثر من خمسين عاماً في هذا الدكّان. كتب فيه لافتات المحلّات، وعقود الزواج، وشواهد القبور، وآيات تُعلَّق في صدور البيوت، ورسائل حبّ لشبّان خجولين لا يُحسنون الكتابة. كان أهل الحيّ يقولون إنّ الكلمة إذا خرجت من قلمه صارت أجمل ممّا كانت في فم قائلها.

وكان له طقس لا يتغيّر. يصل قبل أن تفتح المحلّات أبوابها، فيرشّ الماء أمام الدكّان، ويُعدّ الشاي على موقد صغير، ثم يجلس صامتاً ويبري قلمه. يبريه ببطء شديد، كأنّه يحادثه، فيقطع رأسه بزاوية مائلة، ويشقّه من الوسط شقّاً دقيقاً، ثم يرفعه نحو الضوء وينظر إليه طويلاً. وإذا سأله أحد عن سرّ هذا التأنّي، ابتسم وقال: «القلم يا بنيّ لا يكتب ما في اليد، بل ما في الصدر. فإن كان الصدر مضطرباً، اضطرب الحرف».

في صباح خريفيّ بارد، توقّف أمام الدكّان فتى في الثالثة عشرة من عمره، نحيل الجسم، يحمل حقيبة مدرسية ممزّقة الطرف. ظلّ يقف عند الباب دقائق طويلة يراقب الشيخ وهو يكتب، حتى رفع الشيخ رأسه وقال: «ادخل، فالبرد لا يرحم الواقفين».

دخل الفتى وجلس على طرف السجّادة. كان اسمه سليم، ويسكن في بيت قريب مع أمّه وأخواته الثلاث. قال بصوت خافت إنّ معلّم العربية في المدرسة مزّق دفتره أمام الصفّ كلّه، لأنّ خطّه رديء لا يُقرأ، وإنّ زملاءه ضحكوا منه، وإنّه لا يريد أن يعود إلى المدرسة أبداً.

لم يقل الشيخ شيئاً. ناوله كوباً من الشاي، ثم أخرج ورقة بيضاء ووضع أمامه قلماً من القصب، وقال: «اكتب لي حرف الألف».

كتب سليم ألفاً مائلة مرتجفة، أقرب إلى عود يابس انكسر في منتصفه. نظر إليها الشيخ وقال بهدوء: «هذه ألف خائفة. والحرف الخائف لا يُلام، بل يُطمأن. تعال كلّ يوم بعد المدرسة، وسأعلّمك كيف تطمئنه».

ومنذ ذلك اليوم، صار سليم يأتي كلّ عصر. لم يعلّمه الشيخ في الأسابيع الأولى إلّا الألف. ألفاً بعد ألف، وصفحة بعد صفحة. كان يعلّمه أن يجلس مستقيماً، وأن يتنفّس قبل أن يضع القلم على الورق، وأن يترك الحبر ينساب من غير أن يضغط عليه. وكان يقول له: «الألف هي ميزان الحروف كلّها. إذا استقامت الألف، استقام ما بعدها».

ضجر سليم في البداية. كان يريد أن يكتب كلمات وجملاً، وأن يرى خطّه يتغيّر في يوم وليلة. لكنّ الشيخ كان يأخذ الورقة، فينظر إليها، ثم يضع إصبعه على ألف واحدة من بين مئة، ويقول: «هذه. هذه أحسن من أمس». وكان ذلك يكفي ليعود سليم في اليوم التالي.

ثم جاءت الباء، ثم الجيم، ثم الدال. وتعلّم سليم أنّ لكلّ حرف شخصيّته. فالباء متواضعة، تمدّ ظهرها على السطر كمن يستريح. والجيم كريمة، تفتح ذراعيها لما بعدها. والهاء خجولة، تُخفي وجهها في دائرة صغيرة. والنون، قال الشيخ، كأسٌ ممتلئة، وفي قلبها نقطة كأنّها قطرة ماء.

وكان الدكّان، في تلك العصاري، مسرحاً صغيراً للحيّ كلّه. تدخل امرأة عجوز تريد أن تكتب رسالة إلى ابنها المسافر منذ سنين، فتملي على الشيخ كلماتها وهي تبكي، فيكتبها ببطء، ثم يقرؤها عليها بصوت هادئ حتى تبتسم. ويدخل بقّال الحيّ يطلب لافتة جديدة لمحلّه، ويجادل في الثمن طويلاً، ثم يخرج وقد دفع أكثر ممّا طُلب منه. ويدخل شابّان يحملان ورقة عقد زواج، فيكتب الشيخ اسمي العروسين بماء الذهب، ويدعو لهما بالبركة، ويرفض أن يأخذ أجراً.

وكان سليم يراقب ذلك كلّه من طرف السجّادة، والقلم في يده. فتعلّم أنّ الخطّ ليس حروفاً فحسب، بل هو أمانة. فالكلمة التي يكتبها الخطّاط تبقى بعده، معلّقة على جدار، أو محفوظة في درج، أو منقوشة على حجر. وقد يقرؤها الناس بعد مئة عام، فلا يعرفون من كتبها، لكنّهم يعرفون إن كان قد كتبها بقلب حاضر أو بقلب غائب.

وذات يوم، سأله سليم: «يا شيخ، لماذا لا تكتب اسمك تحت ما تكتب؟». فضحك الشيخ وقال: «الحرف الجميل لا يحتاج إلى توقيع. وإن احتاج إليه، فليس جميلاً بما يكفي».

ومع مرور الشهور، لم يتغيّر خطّ سليم وحده. صار أهدأ، وأقلّ خوفاً، وأكثر صبراً. عاد إلى المدرسة، وصار يكتب دروسه بعناية، وفي آخر العام، رفع معلّم العربية دفتره أمام الصفّ كلّه مرّة أخرى، لكنّه هذه المرّة قال: «انظروا. هكذا يكون الخطّ».

وفي ليلة من ليالي الشتاء، وجد سليم الشيخ جالساً في الدكّان على غير عادته، وأمامه ورقة كبيرة لم يُكتب عليها شيء. كانت يده ترتجف، والقلم ساكن بين أصابعه. سأله سليم عمّا به، فقال الشيخ: «كبرتُ يا سليم. اليد لم تعد تطيعني كما كانت. وطلب منّي إمام الجامع أن أكتب لوحة للمحراب قبل رمضان، ولا أظنّني أقدر».

ظلّ سليم صامتاً طويلاً، ثم قال بصوت لم يعرفه في نفسه من قبل: «أنا أكتبها. وأنت تقول لي أين أخطأت».

ابتسم الشيخ ابتسامة لم يرها سليم على وجهه من قبل، وناوله القلم.

في الأيام الأولى، لم يكتب سليم شيئاً على الورقة الكبيرة. كان الشيخ يقول له: «اقرأ الآية أوّلاً، مرّة ومرّتين وعشراً، حتى تعرف أين يقف المعنى وأين يمتدّ. فالخطّاط الذي لا يفهم ما يكتب، يكتب حروفاً متجاورة لا روح فيها». فكان سليم يقرأ، ويرسم على ورق رخيص مسوّدة بعد مسوّدة، يجرّب فيها توزيع الكلمات، وأين تعلو الألفات، وأين تنحني النونات، وكيف تلتقي الحروف في الوسط كما تلتقي الأيدي في دائرة.

عمل الاثنان على اللوحة أسابيع طويلة. كان سليم يكتب، والشيخ يجلس إلى جانبه ينظر ويهزّ رأسه أو يمسك بيده برفق ليصحّح ميلان حرف أو امتداد كلمة. وكم من ورقة مُزّقت وبدأ من جديد، حتى صارت الحروف على الورق كأنّها تتنفّس. وحين انتهى، رفع الشيخ اللوحة نحو المصباح، ونظر إليها طويلاً، ثم قال كلمة واحدة: «اطمأنّت».

عُلّقت اللوحة في محراب الجامع في أول ليلة من رمضان. ووقف أهل الحيّ يتأمّلونها بعد صلاة التراويح، وسأل بعضهم: «أهذا خطّ الشيخ يوسف؟ ما أجمله!». فقال الشيخ، وهو يضع يده على كتف سليم: «بل خطّ تلميذي. وهو أجمل من خطّي».

مرّت السنوات، ورحل الشيخ يوسف في صباح ربيعيّ هادئ. وحين فتح سليم الدكّان بعد أيام، وجد على الطاولة المنخفضة قلماً من القصب مبريّاً بعناية، وتحته ورقة صغيرة، كُتب عليها بخطّ مرتجف، لكنّه جميل: «القلم الآن لك. فلا تكتب به إلّا ما يطمئن إليه صدرك».

ما زال الدكّان ذو الباب الأزرق في آخر الزقاق. وإذا مررت به في الصباح الباكر، فسترى رجلاً في منتصف العمر، هادئ الملامح، يرشّ الماء أمام الباب، ويُعدّ الشاي على موقد صغير، ثم يجلس صامتاً ويبري قلمه، ببطء شديد، كأنّه يحادثه. وإلى جانبه، في أغلب الأيام، طفل صغير يحمل حقيبة مدرسية، ويكتب على ورقة بيضاء ألفاً خائفة، تنتظر من يطمئنها.
TEXT],

        ['slug' => 'long-ar-stars', 'language' => 'ar', 'dialect' => 'ar-XA', 'voice' => 'ar-Gemini-HD-Puck',
            'style' => 'documentary', 'kind' => 'documentary',
            'title' => 'حين قرأ العرب السماء',
            'text' => <<<'TEXT'
قبل أن تُرسم الخرائط، وقبل أن تُعرف البوصلة، كانت السماء هي الدليل. في ليالي الصحراء الصافية، حيث لا ضوء إلّا ضوء النجوم، تعلّم الإنسان العربيّ أن يقرأ صفحة السماء كما يقرأ صفحة كتاب مفتوح. كانت النجوم تخبره بالطريق، وبالفصول، وبمواعيد المطر، وبالوقت الذي ينبغي أن يرحل فيه أو يقيم.

عرف العرب قبل الإسلام عشرات النجوم بأسمائها. عرفوا الثريّا، ذلك العنقود الصغير المتلألئ الذي كان ظهوره قبل الفجر إيذاناً بدخول الصيف. وعرفوا سهيلاً، النجم اللامع في جنوب السماء، الذي كانوا يقولون إنّه إذا طلع برد الليل، وبدأ الحرّ ينكسر. وعرفوا الجدي، النجم الثابت في الشمال، الذي لا يكاد يتحرّك في مكانه، فكان قبلة المسافرين في الليل، يتّجهون إليه أو يجعلونه خلف ظهورهم.

وكان لهم نظام دقيق يُسمّى الأنواء. قسموا السنة إلى ثمانية وعشرين منزلاً، هي منازل القمر، وكلّ منزل يرتبط بنجم يغرب في الفجر ويطلع نظيره في الجهة المقابلة. ومن طلوع هذه النجوم وغروبها، كانوا يعرفون متى يزرعون، ومتى يحصدون، ومتى يخرجون بإبلهم إلى المراعي، ومتى يتوقّعون المطر. لم يكن ذلك سحراً ولا تنجيماً، بل كان علماً قائماً على المشاهدة الطويلة، تتوارثه الأجيال جيلاً بعد جيل.

ثم جاء الإسلام، فصار للسماء شأن جديد. فالصلاة لها أوقات تُعرف بحركة الشمس، والصيام يبدأ برؤية الهلال وينتهي برؤيته، والحجّ له موسم معلوم، والقبلة لها اتّجاه يجب أن يُعرف بدقّة من كلّ مكان على وجه الأرض. وهكذا، صار علم الفلك حاجة يوميّة، لا ترفاً فكريّاً.

وفي القرن الثالث الهجري، في بغداد، عاصمة الدنيا يومئذٍ، بلغ هذا العلم ذروة لم يبلغها من قبل. أُنشئ بيت الحكمة، وجُمعت فيه الكتب من كلّ الأمم، من اليونان والهند وفارس، وتُرجمت إلى العربية. وبُنيت المراصد، وصُنعت الآلات، واجتمع العلماء من كلّ مكان ليرصدوا السماء ويقيسوها.

كان من هؤلاء العلماء محمد بن موسى الخوارزمي، الذي وضع جداول فلكيّة دقيقة لحركة الشمس والقمر والكواكب. واشترك فلكيّو ذلك العصر في محاولة جريئة لقياس محيط الأرض نفسها. خرجوا إلى سهل منبسط في الصحراء، وساروا شمالاً حتى ارتفع النجم القطبي درجة واحدة، ثم قاسوا المسافة التي قطعوها، وضربوها في عدد الدرجات. وكانت النتيجة قريبة جدّاً ممّا نعرفه اليوم، بفارق ضئيل، قبل أكثر من ألف عام.

وفي الرّقة، على ضفاف الفرات، عاش البتّاني، الذي رصد السماء أكثر من أربعين عاماً. صحّح أخطاء من سبقوه، وقاس طول السنة الشمسيّة بدقّة مدهشة، ووضع جداول اعتمد عليها الفلكيّون في الشرق والغرب قروناً طويلة. وفي أصفهان، كتب عبد الرحمن الصوفي كتابه الشهير «صور الكواكب الثابتة»، فرسم فيه الكوكبات نجماً نجماً، وقدّر لمعان كلّ نجم، وكان أوّل من وصف سحابة خافتة في السماء نعرفها اليوم بأنّها مجرّة أخرى بعيدة.

ولعلّ أجمل ما تركه أولئك العلماء آلة صغيرة من النحاس تُسمّى الأسطرلاب. كانت أشبه بحاسوب يُحمل في اليد. بها يعرف المسافر الوقت في الليل والنهار، ويحدّد ارتفاع الشمس والنجوم، ويستخرج اتّجاه القبلة، ويعرف موقعه على الأرض. وقد صنع الحرفيّون منها تحفاً فنّيّة، نُقشت عليها الأسماء والزخارف بدقّة متناهية، وما زال كثير منها محفوظاً في متاحف العالم حتى اليوم.

ومن آثار ذلك العصر ما لا نزال نراه كلّ ليلة دون أن ننتبه. فكثير من النجوم اللامعة في السماء ما زالت تحمل أسماءها العربيّة في لغات العالم كلّه. فالنسر الواقع، والنسر الطائر، والدبران، ورجل الجبّار، وإبط الجوزاء، والذنب. أسماء خرجت من خيام الصحراء ومراصد بغداد، وسافرت عبر القرون والبحار، حتى صارت جزءاً من لغة العلم في كلّ مكان.

لم يكن الفلكيّون العرب مجرّد ناقلين لعلم من سبقهم. بل كانوا يرصدون ويقيسون ويشكّون ويصحّحون. كتب ابن الهيثم رسالة ينقد فيها نماذج القدماء لحركة الكواكب، وقال إنّ على العالم أن يجعل نفسه خصماً لكلّ ما يقرأ، فلا يقبل شيئاً إلّا بالدليل. وكانت هذه الروح، روح السؤال والتجربة، هي الجسر الذي عبر عليه العلم من عصر إلى عصر.

واليوم، حين تخرج في ليلة صافية بعيداً عن أضواء المدينة، وترفع رأسك إلى السماء، فأنت تنظر إلى الصفحة نفسها التي قرأها أولئك الرجال قبل أكثر من ألف عام. الثريّا ما زالت تتلألأ في مكانها، وسهيل ما زال يطلع في الجنوب معلناً انكسار الحرّ، والجدي ما زال ثابتاً في الشمال يدلّ التائهين على الطريق. تغيّرت الأدوات، وكبرت المراصد، وصارت المناظير تطوف في الفضاء، لكنّ السؤال ما زال كما هو: ما الذي تخبرنا به هذه النجوم؟ وكم بقي في السماء ممّا لم نقرأه بعد؟
TEXT],

    ],
];
