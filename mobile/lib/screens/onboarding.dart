import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/app_state.dart';
import '../core/geo.dart';
import '../core/signing.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Before election day: practice run, quiz, and confirming attendance.
class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key, required this.onboarding});
  final Map<String, dynamic> onboarding;
  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final app = AppState.instance;
  late Map<String, dynamic> o = widget.onboarding;
  late int stage = (o['step'] as int? ?? 0) < 3
      ? 0
      : (o['step'] as int) < 4
      ? 1
      : 2;

  Future<void> reload() async {
    try {
      final fresh = await app.api.get('onboarding');
      setState(() => o = fresh);
    } catch (_) {}
  }

  void next() async {
    await reload();
    setState(() => stage += 1);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(
        ['Practice run', 'Training quiz', 'Ready for election day'][stage.clamp(
          0,
          2,
        )],
      ),
    ),
    body: Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
          child: StepBar(
            step: stage,
            labels: const ['Practice', 'Quiz', 'Confirm'],
          ),
        ),
        Expanded(
          child: switch (stage) {
            0 => _Practice(onDone: next),
            1 => _Quiz(
              questions: [
                for (final q in (o['quiz'] as List? ?? []))
                  Map<String, dynamic>.from(q),
              ],
              passMark: o['quiz_pass_mark'] ?? 8,
              onPassed: next,
            ),
            _ => _Confirm(
              electionDate: o['election_date'],
              onDone: () => Navigator.pop(context),
            ),
          },
        ),
      ],
    ),
  );
}

class _Practice extends StatefulWidget {
  const _Practice({required this.onDone});
  final VoidCallback onDone;
  @override
  State<_Practice> createState() => _PracticeState();
}

class _PracticeState extends State<_Practice> {
  final app = AppState.instance;
  bool busy = false;
  String? error;
  Map<String, dynamic>? result;
  Duration? took;

  Future<void> run() async {
    setState(() {
      busy = true;
      error = null;
    });
    final started = DateTime.now();
    try {
      await app.ensureDevice();
      Fix? fix;
      try {
        fix = await Geo.current().timeout(const Duration(seconds: 10));
      } catch (_) {}
      // The sample EC8A from the training pack.
      final votes = {
        for (final (i, p) in app.parties.indexed)
          p['code'] as String: [214, 187, 22, 9][i % 4],
      };
      final capturedAt = Canonical.timestamp(DateTime.now());
      final lat = Canonical.coord(fix?.latitude),
          lng = Canonical.coord(fix?.longitude);
      final canonical = Canonical.payload(
        unit: 'PRACTICE',
        accredited: 448,
        rejected: 6,
        votes: votes,
        latitude: lat,
        longitude: lng,
        capturedAt: capturedAt,
      );
      final res = await app.api.post('onboarding/practice', {
        'device_id': app.deviceId,
        'signature': await app.key!.sign(canonical),
        'captured_at': capturedAt,
        'accredited': 448,
        'rejected': 6,
        'votes': votes,
        'latitude': lat,
        'longitude': lng,
      });
      setState(() {
        result = res;
        took = DateTime.now().difference(started);
      });
    } on ApiException catch (e) {
      setState(() => error = e.first);
    } on NetworkException {
      setState(
        () => error = 'No connection. The practice run needs data so you can see the command room receive it.',
      );
    } catch (e) {
      setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
    children: [
      Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: C.warnBg,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: const Color(0xFFD99A00),
            style: BorderStyle.solid,
          ),
        ),
        child: const Text(
          'PRACTICE MODE · NOTHING HERE COUNTS',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: C.warn,
            fontWeight: FontWeight.w800,
            letterSpacing: 1,
          ),
        ),
      ),
      const SizedBox(height: 16),
      const Text(
        'Send a practice result',
        style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 4),
      const Text(
        'This signs the sample EC8A from your training pack with your phone\'s key and sends it, exactly like on election day.',
        style: TextStyle(color: C.ink2, fontSize: 14.5),
      ),
      const SizedBox(height: 16),
      SectionCard(
        child: Column(
          children: [
            for (final (i, p) in AppState.instance.parties.indexed)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(
                  children: [
                    Text('${p['code']} · ${p['name']}'),
                    const Spacer(),
                    Text(
                      '${[214, 187, 22, 9][i % 4]}',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ],
                ),
              ),
            const Divider(),
            const Row(
              children: [
                Text(
                  'Accredited 448 · Rejected 6',
                  style: TextStyle(color: C.ink2),
                ),
              ],
            ),
          ],
        ),
      ),
      const SizedBox(height: 14),
      if (result != null) ...[
        InfoBox(
          'The command room got it in ${took!.inMilliseconds / 1000 < 1 ? 'under a second' : '${(took!.inMilliseconds / 1000).toStringAsFixed(0)} seconds'}. Reference ${result!['fingerprint']}. Your phone is ready.',
          title: 'Practice result received',
          icon: Icons.check_circle,
        ),
        const SizedBox(height: 14),
        FilledButton(
          key: const Key('to-quiz'),
          onPressed: widget.onDone,
          child: const Text('Continue to quiz'),
        ),
        const SizedBox(height: 8),
        OutlinedButton(onPressed: run, child: const Text('Practise again')),
      ] else ...[
        if (error != null) ...[
          InfoBox(error!, tone: 'bad'),
          const SizedBox(height: 12),
        ],
        FilledButton(
          key: const Key('send-practice'),
          onPressed: busy ? null : run,
          child: Text(
            busy ? 'Signing and sending…' : 'Sign and send practice result',
          ),
        ),
      ],
    ],
  );
}

class _Quiz extends StatefulWidget {
  const _Quiz({
    required this.questions,
    required this.passMark,
    required this.onPassed,
  });
  final List<Map<String, dynamic>> questions;
  final int passMark;
  final VoidCallback onPassed;
  @override
  State<_Quiz> createState() => _QuizState();
}

class _QuizState extends State<_Quiz> {
  int i = 0;
  final answers = <String, String>{};
  Map<String, dynamic>? marked;
  bool busy = false;
  String? error;

  Future<void> submit() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final res = await AppState.instance.api.post('onboarding/quiz', {
        'answers': answers,
      });
      setState(() => marked = res);
    } catch (e) {
      setState(
        () => error = e is NetworkException
            ? 'No connection. Your answers are kept; try again when you have data.'
            : e.toString(),
      );
    } finally {
      setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (widget.questions.isEmpty) {
      return const Center(child: Text('Connect to data to load the quiz.'));
    }
    if (marked != null) return _results();
    final q = widget.questions[i];
    final options = Map<String, dynamic>.from(q['options']);
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        Row(
          children: [
            for (var k = 0; k < widget.questions.length; k++) ...[
              if (k > 0) const SizedBox(width: 4),
              Expanded(
                child: Container(
                  height: 6,
                  decoration: BoxDecoration(
                    color: k < i
                        ? C.good
                        : k == i
                        ? C.action
                        : const Color(0xFFDDE1E8),
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 8),
        Text(
          'Question ${i + 1} of ${widget.questions.length}',
          style: const TextStyle(color: C.ink2, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 10),
        Text(
          q['question'],
          style: const TextStyle(
            fontSize: 21,
            fontWeight: FontWeight.w800,
            height: 1.3,
          ),
        ),
        const SizedBox(height: 16),
        for (final e in options.entries)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Material(
              color: answers[q['id']] == e.key
                  ? const Color(0xFFEEF0F4)
                  : Colors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(14),
                side: BorderSide(
                  color: answers[q['id']] == e.key
                      ? C.navy
                      : const Color(0xFFC9CED6),
                  width: answers[q['id']] == e.key ? 2 : 1.5,
                ),
              ),
              child: InkWell(
                key: Key('${q['id']}-${e.key}'),
                borderRadius: BorderRadius.circular(14),
                onTap: () => setState(() => answers[q['id']] = e.key),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(
                    children: [
                      CircleAvatar(
                        radius: 14,
                        backgroundColor: answers[q['id']] == e.key
                            ? C.navy
                            : const Color(0xFFEEF0F4),
                        child: Text(
                          e.key.toUpperCase(),
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w800,
                            color: answers[q['id']] == e.key
                                ? Colors.white
                                : C.navy,
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          '${e.value}',
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        if (error != null) ...[
          InfoBox(error!, tone: 'bad'),
          const SizedBox(height: 10),
        ],
        const SizedBox(height: 6),
        FilledButton(
          key: const Key('quiz-next'),
          onPressed: answers[q['id']] == null || busy
              ? null
              : () => i < widget.questions.length - 1
                    ? setState(() => i++)
                    : submit(),
          child: Text(
            i < widget.questions.length - 1
                ? 'Next question'
                : busy
                ? 'Marking…'
                : 'Finish quiz',
          ),
        ),
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Text(
            'Pass with ${widget.passMark} of ${widget.questions.length}. You can retake the quiz.',
            textAlign: TextAlign.center,
            style: const TextStyle(color: C.ink2, fontSize: 12.5),
          ),
        ),
      ],
    );
  }

  Widget _results() {
    final passed = marked!['passed'] == true;
    final byId = {for (final q in widget.questions) q['id']: q};
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        InfoBox(
          passed
              ? 'You know what to do on election day.'
              : 'Read the explanations below, then try again.',
          title:
              '${marked!['score']} of ${marked!['out_of']} · ${passed ? 'passed' : 'not passed yet'}',
          tone: passed ? 'good' : 'warn',
          icon: passed ? Icons.emoji_events_outlined : Icons.refresh,
        ),
        const SizedBox(height: 12),
        for (final m in (marked!['questions'] as List).where(
          (m) => m['correct'] != true,
        ))
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    byId[m['id']]?['question'] ?? '',
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Answer: ${byId[m['id']]?['options'][m['answer']]}',
                    style: const TextStyle(
                      color: C.good,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  Text(m['explain'], style: const TextStyle(color: C.ink2)),
                ],
              ),
            ),
          ),
        const SizedBox(height: 10),
        if (passed)
          FilledButton(
            key: const Key('to-confirm'),
            onPressed: widget.onPassed,
            child: const Text('Continue'),
          )
        else
          FilledButton(
            onPressed: () => setState(() {
              marked = null;
              i = 0;
              answers.clear();
            }),
            child: const Text('Retake the quiz'),
          ),
      ],
    );
  }
}

class _Confirm extends StatefulWidget {
  const _Confirm({required this.electionDate, required this.onDone});
  final String? electionDate;
  final VoidCallback onDone;
  @override
  State<_Confirm> createState() => _ConfirmState();
}

class _ConfirmState extends State<_Confirm> {
  final checks = {
    'Phone charged': false,
    'Power bank packed': false,
    'Data bundle active': false,
    'Agent letter and ID ready': false,
    'Know the route to your unit': false,
  };
  bool busy = false;

  Future<void> answer(bool available, [String? note]) async {
    setState(() => busy = true);
    final app = AppState.instance;
    await app.outbox.add(
      kind: 'confirm',
      path: 'onboarding/confirm',
      title: available ? 'Ready for election day' : "Can't make election day",
      fields: {'available': available, 'note': note},
    );
    await app.outbox.flush();
    if (!mounted) return;
    toast(
      context,
      available
          ? 'Thank you. You are confirmed.'
          : 'Thank you for telling us. Your supervisor will arrange cover.',
    );
    widget.onDone();
  }

  Future<void> cannot() async {
    final c = TextEditingController();
    final note = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text("Can't make it?"),
        content: TextField(
          controller: c,
          decoration: const InputDecoration(
            hintText: 'Tell your supervisor why (optional)',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, c.text),
            child: const Text('Tell my supervisor'),
          ),
        ],
      ),
    );
    if (note != null) await answer(false, note.isEmpty ? null : note);
  }

  @override
  Widget build(BuildContext context) {
    final unit = AppState.instance.unit;
    final date = widget.electionDate == null
        ? null
        : DateTime.parse(widget.electionDate!);
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: C.navy,
            borderRadius: BorderRadius.circular(18),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (date != null)
                Text(
                  'ELECTION DAY · ${days[date.weekday - 1].toUpperCase()} ${date.day} ${months[date.month - 1].toUpperCase()} ${date.year}',
                  style: const TextStyle(
                    color: Color(0xFFFFB48A),
                    fontWeight: FontWeight.w800,
                    fontSize: 12,
                    letterSpacing: 1.2,
                  ),
                ),
              const SizedBox(height: 6),
              Text(
                'Be at ${unit?['code']} by 7:30 am',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 22,
                  fontWeight: FontWeight.w800,
                ),
              ),
              Text(
                '${unit?['name']} · ${unit?['ward']} · ${unit?['lga']}',
                style: const TextStyle(color: Color(0xFFC9D1DE)),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        Card(
          child: Column(
            children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(16, 14, 16, 4),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: Text(
                    'Before you sleep',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
              ),
              for (final k in checks.keys)
                CheckboxListTile(
                  value: checks[k],
                  onChanged: (v) => setState(() => checks[k] = v ?? false),
                  title: Text(
                    k,
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  activeColor: C.good,
                ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('ready'),
          style: FilledButton.styleFrom(backgroundColor: C.good),
          onPressed: busy || checks.values.any((v) => !v)
              ? null
              : () => answer(true),
          child: const Text("I'm ready for election day"),
        ),
        const SizedBox(height: 10),
        OutlinedButton(
          onPressed: busy ? null : cannot,
          child: const Text("I can't make it, tell my supervisor"),
        ),
      ],
    );
  }
}
