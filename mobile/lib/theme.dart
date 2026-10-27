import 'package:flutter/material.dart';

class C {
  static const navy = Color(0xFF13213C);
  static const navy2 = Color(0xFF1E2F52);
  static const ink2 = Color(0xFF4A5464);
  static const ink3 = Color(0xFF8D96A5);
  static const mist = Color(0xFFF7F8FA);
  static const line = Color(0xFFE1E4EA);
  static const action = Color(0xFFC2410C);
  static const orange = Color(0xFFE8590C);
  static const good = Color(0xFF17694B);
  static const goodBg = Color(0xFFE3F4EC);
  static const warn = Color(0xFF7A4F00);
  static const warnBg = Color(0xFFFFF1CC);
  static const bad = Color(0xFF8F350A);
  static const badBg = Color(0xFFFDE7DA);
}

ThemeData buildTheme() {
  final base = ThemeData(
    useMaterial3: true,
    fontFamily: 'Inter',
    colorScheme: ColorScheme.fromSeed(
      seedColor: C.navy,
      primary: C.navy,
      secondary: C.action,
      surface: Colors.white,
    ),
    scaffoldBackgroundColor: C.mist,
  );
  final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(14));
  return base.copyWith(
    textTheme: base.textTheme.apply(bodyColor: C.navy, displayColor: C.navy),
    appBarTheme: const AppBarTheme(
      backgroundColor: C.mist,
      foregroundColor: C.navy,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: TextStyle(
        color: C.navy,
        fontSize: 19,
        fontWeight: FontWeight.w800,
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: C.action,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(54),
        shape: shape,
        textStyle: const TextStyle(fontSize: 16.5, fontWeight: FontWeight.w700),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: C.navy,
        side: const BorderSide(color: C.navy, width: 1.5),
        minimumSize: const Size.fromHeight(48),
        shape: shape,
        textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Color(0xFFC9CED6)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Color(0xFFC9CED6)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: C.navy, width: 2),
      ),
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: const BorderSide(color: C.line),
      ),
    ),
    dividerTheme: const DividerThemeData(color: Color(0xFFEEF0F3), space: 1),
  );
}
