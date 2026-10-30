import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../theme.dart';
import '../widgets/common.dart';

class FallbackScreen extends StatelessWidget {
  const FallbackScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppState.instance;
    final unit = app.unit?['code'] ?? 'PU004';
    final number =
        app.rules['sms_number'] as String? ?? '[campaign SMS number]';
    final ussd = app.rules['ussd_code'] as String?;
    final sample =
        'R $unit AC448 ${app.parties.take(4).map((p) => '${p['code']}${[214, 187, 22, 9][app.parties.indexOf(p) % 4]}').join(' ')} X6';
    return Scaffold(
      appBar: AppBar(title: const Text('If the app fails')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
        children: [
          const Text(
            'Use any phone, even without data. Your supervisor will still see your result.',
            style: TextStyle(color: C.ink2, fontSize: 15),
          ),
          if (ussd != null) ...[
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: C.action,
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'EASIEST: DIAL FROM THIS PHONE',
                    style: TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 12.5,
                      letterSpacing: 1,
                    ),
                  ),
                  const SizedBox(height: 8),
                  SelectableText(
                    ussd,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 26,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Choose 1, then type each figure when asked. No data needed. You get a reference number at the end.',
                    style: TextStyle(color: Colors.white, fontSize: 14),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: C.navy,
              borderRadius: BorderRadius.circular(18),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'TEXT THE RESULT TO $number',
                  style: const TextStyle(
                    color: Color(0xFFFFB48A),
                    fontWeight: FontWeight.w800,
                    fontSize: 12.5,
                    letterSpacing: 1,
                  ),
                ),
                const SizedBox(height: 10),
                SelectableText(
                  sample,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                    fontFamily: 'monospace',
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          SectionCard(
            child: Column(
              children: [
                _row('R $unit', 'Result for $unit'),
                _row('AC448', 'Accredited voters'),
                _row(
                  '${app.parties.isEmpty ? 'A' : app.parties.first['code']}214 …',
                  'Votes per party, by party code',
                ),
                _row('X6', 'Rejected ballots'),
              ],
            ),
          ),
          const SizedBox(height: 14),
          InfoBox(
            'OK $unit total 438 ref 7F2A9C1E',
            title: "You'll get a reply within a minute",
            icon: Icons.mark_chat_read_outlined,
          ),
          const SizedBox(height: 10),
          const Text(
            'No reply? Send again or call your supervisor. Keep the EC8A photo on your phone and send it from the app when data returns; it is linked to your SMS result automatically.',
            style: TextStyle(color: C.ink2, fontSize: 13.5),
          ),
          const SizedBox(height: 18),
          const Text(
            'Before election day, the campaign also sends you a confirmation text. Reply YES if you will be at your unit, or NO if you can\'t make it.',
            style: TextStyle(color: C.ink2, fontSize: 13.5),
          ),
        ],
      ),
    );
  }

  Widget _row(String a, String b) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Row(
      children: [
        SizedBox(
          width: 110,
          child: Text(
            a,
            style: const TextStyle(
              fontWeight: FontWeight.w800,
              fontFamily: 'monospace',
            ),
          ),
        ),
        Expanded(
          child: Text(b, style: const TextStyle(color: C.ink2)),
        ),
      ],
    ),
  );
}
