import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'fallback.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppState.instance;
    final me = app.me ?? {};
    final unit = app.unit;
    final initials = (me['name'] as String? ?? '?')
        .split(' ')
        .where((w) => w.isNotEmpty)
        .map((w) => w[0])
        .take(2)
        .join()
        .toUpperCase();
    return Scaffold(
      appBar: AppBar(title: const Text('Profile and settings')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
        children: [
          SectionCard(
            child: Row(
              children: [
                CircleAvatar(
                  radius: 26,
                  backgroundColor: C.navy,
                  child: Text(
                    initials,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${me['name']} · ${me['code'] ?? ''}',
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                      Text(
                        '${me['role_label'] ?? ''}${unit != null ? ' · ${unit['code']}, ${unit['ward']}, ${unit['lga']}' : ''}',
                        style: const TextStyle(color: C.ink2, fontSize: 13),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          Card(
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.sms_outlined),
                  title: const Text('If the app fails: SMS'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => const FallbackScreen()),
                  ),
                ),
                const Divider(),
                ListTile(
                  leading: const Icon(Icons.key_outlined),
                  title: const Text('Signing key'),
                  subtitle: Text(
                    app.key == null
                        ? 'Not created'
                        : 'Registered · ${app.key!.publicKeyB64.substring(0, 12)}…',
                  ),
                ),
                const Divider(),
                ListTile(
                  leading: const Icon(Icons.dns_outlined),
                  title: const Text('Server'),
                  subtitle: Text(app.api.baseUrl),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          OutlinedButton(
            onPressed: () async {
              final ok = await app.signOut();
              if (!ok && context.mounted) {
                toast(
                  context,
                  "You can't sign out while records are waiting to send.",
                );
              } else if (context.mounted) {
                Navigator.popUntil(context, (r) => r.isFirst);
              }
            },
            child: const Text('Sign out'),
          ),
          const SizedBox(height: 10),
          const Text(
            "You can't sign out while records are waiting to send · PollProof agent app 1.0",
            textAlign: TextAlign.center,
            style: TextStyle(color: C.ink2, fontSize: 12.5),
          ),
        ],
      ),
    );
  }
}
