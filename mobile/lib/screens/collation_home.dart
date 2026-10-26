import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/app_state.dart';
import '../core/signing.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/logo.dart';
import 'incident.dart';
import 'messages.dart';
import 'settings.dart';

/// Collation agents type the declared EC8B (ward) or EC8C (LGA) figures; they're compared with our unit results at once.
class CollationHome extends StatefulWidget {
  const CollationHome({super.key});
  @override
  State<CollationHome> createState() => _CollationHomeState();
}

class _CollationHomeState extends State<CollationHome> {
  final app = AppState.instance;
  late String level = app.me?['ward'] != null ? 'ward' : 'lga';
  late final votes = {
    for (final p in app.parties) p['code'] as String: TextEditingController(),
  };
  final rejected = TextEditingController(),
      accredited = TextEditingController();
  bool busy = false;
  Map<String, dynamic>? comparison;
  String? error;

  int _n(TextEditingController c) => int.tryParse(c.text) ?? 0;

  Future<void> submit() async {
    setState(() {
      busy = true;
      error = null;
      comparison = null;
    });
    try {
      final res = await app.api.post('collation-entries', {
        'level': level,
        'scope_id': level == 'ward'
            ? app.me?['ward']?['id']
            : app.me?['lga']?['id'],
        'votes': {for (final e in votes.entries) e.key: _n(e.value)},
        'rejected': _n(rejected),
        if (accredited.text.isNotEmpty) 'accredited': _n(accredited),
        'announced_at': Canonical.timestamp(DateTime.now()),
      });
      setState(() => comparison = Map<String, dynamic>.from(res['comparison']));
    } on ApiException catch (e) {
      setState(() => error = e.first);
    } on NetworkException {
      setState(
        () => error = 'No connection. Call the figures in to the situation room, then try again.',
      );
    } finally {
      setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final place = level == 'ward'
        ? '${app.me?['ward']?['name']} · ${app.me?['lga']?['name']}'
        : '${app.me?['lga']?['name']}';
    final diffs = Map<String, dynamic>.from(comparison?['differences'] ?? {});
    return Scaffold(
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          Container(
            color: C.navy,
            padding: EdgeInsets.fromLTRB(
              20,
              MediaQuery.of(context).padding.top + 14,
              12,
              20,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const PinLogo(size: 26),
                    const SizedBox(width: 8),
                    const Wordmark(size: 20),
                    const Spacer(),
                    IconButton(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => const MessagesScreen(),
                        ),
                      ),
                      icon: const Icon(
                        Icons.notifications_none,
                        color: Colors.white,
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => const SettingsScreen(),
                        ),
                      ),
                      icon: const Icon(
                        Icons.account_circle_outlined,
                        color: Colors.white,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                const Text(
                  'COLLATION CENTRE',
                  style: TextStyle(
                    color: Color(0xFFFFB48A),
                    fontWeight: FontWeight.w800,
                    fontSize: 12.5,
                    letterSpacing: 1,
                  ),
                ),
                Text(
                  place,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (app.me?['ward'] != null && app.me?['lga'] != null)
                  SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(value: 'ward', label: Text('Ward · EC8B')),
                      ButtonSegment(value: 'lga', label: Text('LGA · EC8C')),
                    ],
                    selected: {level},
                    onSelectionChanged: (s) => setState(() {
                      level = s.first;
                      comparison = null;
                    }),
                  ),
                const SizedBox(height: 12),
                const Text(
                  'Type the figures exactly as announced.',
                  style: TextStyle(color: C.ink2),
                ),
                const SizedBox(height: 10),
                SectionCard(
                  child: Column(
                    children: [
                      for (final p in app.parties)
                        _field(
                          '${p['code']} · ${p['name']}',
                          votes[p['code']]!,
                        ),
                      const Divider(),
                      _field('Rejected ballots', rejected),
                      _field('Accredited (if declared)', accredited),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                if (error != null) ...[
                  InfoBox(error!, tone: 'bad'),
                  const SizedBox(height: 10),
                ],
                if (comparison != null) ...[
                  diffs.isEmpty
                      ? InfoBox(
                          'Matches our signed unit results (${comparison!['units_reported']} of ${comparison!['units_in_scope']} units in).',
                          title: 'No difference',
                          icon: Icons.check_circle,
                        )
                      : InfoBox(
                          'Declared minus our units: ${diffs.entries.map((e) => '${e.key} ${e.value > 0 ? '+' : ''}${e.value}').join(', ')}. The legal team has been alerted. Ask for the sheet to be checked before it is signed.',
                          title: comparison!['coverage_complete'] == true
                              ? 'Declared figures differ'
                              : 'Differs, but only ${comparison!['units_reported']} of ${comparison!['units_in_scope']} units are in',
                          tone: 'bad',
                          icon: Icons.warning_amber,
                        ),
                  const SizedBox(height: 12),
                ],
                FilledButton(
                  onPressed: busy ? null : submit,
                  child: Text(busy ? 'Checking…' : 'Compare with our units'),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => const IncidentScreen()),
                  ),
                  icon: const Icon(Icons.report_outlined),
                  label: const Text('Report incident'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _field(String label, TextEditingController c) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 5),
    child: Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
        SizedBox(
          width: 120,
          child: TextField(
            controller: c,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            textAlign: TextAlign.right,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
          ),
        ),
      ],
    ),
  );
}
