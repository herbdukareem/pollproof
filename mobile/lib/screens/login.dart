import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/app_state.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/logo.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final phone = TextEditingController();
  final code = TextEditingController();
  bool sent = false, busy = false;
  String? error;
  int wait = 0;
  Timer? _t;

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  Future<void> send() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await AppState.instance.requestCode(phone.text.trim());
      setState(() {
        sent = true;
        wait = 45;
      });
      _t?.cancel();
      _t = Timer.periodic(
        const Duration(seconds: 1),
        (t) => setState(() {
          if (--wait <= 0) t.cancel();
        }),
      );
    } on ApiException catch (e) {
      setState(
        () => error = e.status == 429
            ? 'Too many tries. Wait a minute and try again.'
            : e.first,
      );
    } on NetworkException {
      setState(() => error = 'No connection. Check your data and try again.');
    } finally {
      setState(() => busy = false);
    }
  }

  Future<void> verify() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await AppState.instance.verifyCode(phone.text.trim(), code.text.trim());
    } on ApiException catch (e) {
      setState(() => error = e.first);
    } on NetworkException {
      setState(() => error = 'No connection. Check your data and try again.');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> server() async {
    final c = TextEditingController(text: AppState.instance.api.baseUrl);
    final url = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Server'),
        content: TextField(
          controller: c,
          keyboardType: TextInputType.url,
          decoration: const InputDecoration(hintText: 'https://…'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, c.text),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    if (url != null && url.isNotEmpty) await AppState.instance.setServer(url);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: C.navy,
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.fromLTRB(22, 28, 22, 22),
        children: [
          Row(
            children: [
              GestureDetector(
                onLongPress: server,
                child: const PinLogo(size: 34),
              ),
              const SizedBox(width: 10),
              const Wordmark(size: 26),
              const Spacer(),
              const Text(
                'Agent app',
                style: TextStyle(
                  color: Color(0xFFC9D1DE),
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
          const SizedBox(height: 40),
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'Sign in',
                  style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Use the phone number your campaign registered for you.',
                  style: TextStyle(color: C.ink2, fontSize: 14.5),
                ),
                const SizedBox(height: 20),
                const Label('Phone number'),
                TextField(
                  key: const Key('phone'),
                  controller: phone,
                  enabled: !sent,
                  keyboardType: TextInputType.phone,
                  autofillHints: const [AutofillHints.telephoneNumber],
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w600,
                  ),
                  decoration: const InputDecoration(hintText: '0803 123 4567'),
                ),
                if (sent) ...[
                  const SizedBox(height: 16),
                  const Label('6-digit code sent by SMS'),
                  TextField(
                    key: const Key('code'),
                    controller: code,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    autofillHints: const [AutofillHints.oneTimeCode],
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 24,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 8,
                    ),
                    decoration: const InputDecoration(
                      counterText: '',
                      hintText: '000000',
                    ),
                  ),
                  Row(
                    children: [
                      const Text(
                        "Didn't get it?",
                        style: TextStyle(color: C.ink2),
                      ),
                      TextButton(
                        onPressed: wait > 0 || busy ? null : send,
                        child: Text(
                          wait > 0
                              ? 'Resend in 0:${wait.toString().padLeft(2, '0')}'
                              : 'Resend code',
                        ),
                      ),
                      const Spacer(),
                      TextButton(
                        onPressed: () => setState(() {
                          sent = false;
                          code.clear();
                        }),
                        child: const Text('Change number'),
                      ),
                    ],
                  ),
                ],
                if (error != null) ...[
                  const SizedBox(height: 12),
                  InfoBox(error!, tone: 'bad'),
                ],
                const SizedBox(height: 18),
                FilledButton(
                  key: const Key('submit'),
                  onPressed: busy ? null : (sent ? verify : send),
                  child: Text(
                    busy
                        ? 'Please wait…'
                        : sent
                        ? 'Verify and continue'
                        : 'Send me a code',
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          const Text(
            'Trouble signing in? Call your ward supervisor.',
            textAlign: TextAlign.center,
            style: TextStyle(color: Color(0xFFC9D1DE)),
          ),
        ],
      ),
    ),
  );
}
