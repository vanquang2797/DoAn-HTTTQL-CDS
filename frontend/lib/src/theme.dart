import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

abstract final class BabiColors {
  static const primary = Color(0xFFF2674A);
  static const ink = Color(0xFF2D2A32);
  static const cream = Color(0xFFFFF8F1);
  static const secondary = Color(0xFF6E6A75);
  static const border = Color(0xFFEFE6DC);
  static const selected = Color(0xFFFBE3DB);
  static const error = Color(0xFFD93025);
  static const success = Color(0xFF1F7A52);
  static const successBg = Color(0xFFE3F4EA);
  static const warning = Color(0xFFF6B800);
  static const glassWhite = Color(0xDFFFFFFF);
  static const glassBorder = Color(0xBFFFFFFF);
  static const glassTint = Color(0x88FFF8F1);
  static const blush = Color(0xFFFFD9D2);
  static const mint = Color(0xFFBFEBDD);
}

TextStyle textStyle(
  double size, {
  double? line,
  FontWeight weight = FontWeight.w400,
  Color color = BabiColors.ink,
}) => TextStyle(
  fontFamily: 'Be Vietnam Pro',
  fontSize: size,
  height: (line ?? size * 1.4) / size,
  fontWeight: weight,
  color: color,
);

ThemeData babiTheme() => ThemeData(
  useMaterial3: true,
  fontFamily: 'Be Vietnam Pro',
  scaffoldBackgroundColor: BabiColors.cream,
  canvasColor: BabiColors.cream,
  splashFactory: InkSparkle.splashFactory,
  colorScheme: ColorScheme.fromSeed(seedColor: BabiColors.primary).copyWith(
    primary: BabiColors.primary,
    onPrimary: BabiColors.ink,
    surface: BabiColors.cream,
    onSurface: BabiColors.ink,
    error: BabiColors.error,
  ),
  textTheme: TextTheme(
    titleLarge: textStyle(22, line: 28, weight: FontWeight.w600),
    titleMedium: textStyle(16, line: 22, weight: FontWeight.w600),
    bodyLarge: textStyle(16, line: 24),
    bodyMedium: textStyle(14, line: 20),
    bodySmall: textStyle(13, line: 18, color: BabiColors.secondary),
  ),
  filledButtonTheme: FilledButtonThemeData(
    style: FilledButton.styleFrom(
      minimumSize: const Size.fromHeight(52),
      textStyle: textStyle(16, weight: FontWeight.w600),
      backgroundColor: BabiColors.primary,
      foregroundColor: Colors.white,
      elevation: 3,
      shadowColor: BabiColors.primary.withValues(alpha: .25),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
    ),
  ),
  inputDecorationTheme: InputDecorationTheme(
    filled: true,
    fillColor: BabiColors.glassWhite,
    border: OutlineInputBorder(
      borderRadius: BorderRadius.circular(16),
      borderSide: const BorderSide(color: BabiColors.border),
    ),
    enabledBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(16),
      borderSide: const BorderSide(color: BabiColors.border),
    ),
  ),
  bottomSheetTheme: const BottomSheetThemeData(
    backgroundColor: Color(0xF5FFF8F1),
    modalBackgroundColor: Color(0xF5FFF8F1),
    showDragHandle: true,
  ),
  dialogTheme: DialogThemeData(
    backgroundColor: BabiColors.cream.withValues(alpha: .96),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
  ),
  snackBarTheme: SnackBarThemeData(
    behavior: SnackBarBehavior.floating,
    backgroundColor: BabiColors.ink.withValues(alpha: .92),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
  ),
  appBarTheme: const AppBarTheme(
    backgroundColor: Colors.transparent,
    surfaceTintColor: Colors.transparent,
    systemOverlayStyle: SystemUiOverlayStyle.dark,
    elevation: 0,
  ),
);
