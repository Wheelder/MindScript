#!/usr/bin/env python3
"""
MindScript Compiler v0.0.0.1
==============================
Founded by Abdul Baays Fakhri
Vancouver, BC, Canada — July 2, 2025

MindScript is an AI-native programming language where you describe WHAT you
want to achieve in plain English, and the compiler learns your human
problem-solving process to execute it as optimized code.

Instead of learning machine language syntax, machines learn human logic.

Usage:
    python3 mindscript_compiler.py program.ms
    mindscript program.ms        (after setup)

File extension: .ms
"""

import sys
import re
import ast
from collections import Counter


class MindScriptCompiler:
    """
    Core compiler that maps natural language problem descriptions to
    optimized algorithm patterns. Understands human intent instead of
    requiring humans to learn machine syntax.
    """

    def __init__(self):
        # Pattern library: common algorithmic problem patterns
        # Maps plain English phrases → optimized implementations
        self.patterns = {
            'find_duplicates':      self._pattern_find_duplicates,
            'two_sum':              self._pattern_two_sum,
            'longest_consecutive':  self._pattern_longest_consecutive,
            'valid_parentheses':    self._pattern_valid_parentheses,
            'group_by':             self._pattern_group_by,
            'find_max':             self._pattern_find_max,
            'find_min':             self._pattern_find_min,
            'sort_elements':        self._pattern_sort_elements,
            'remove_duplicates':    self._pattern_remove_duplicates,
            'count_occurrences':    self._pattern_count_occurrences,
        }

        # Intent keywords: maps natural language phrases to pattern names
        self.intent_map = {
            r'find.*(repeated|duplicate).*element': 'find_duplicates',
            r'count.*(repeated|duplicate|occurrence)': 'find_duplicates',
            r'how many times.*repeated': 'find_duplicates',
            r'two numbers.*sum.*target': 'two_sum',
            r'find.*pair.*sum': 'two_sum',
            r'longest.*consecutive.*sequence': 'longest_consecutive',
            r'check.*parenthes.*balanced': 'valid_parentheses',
            r'valid.*parenthes': 'valid_parentheses',
            r'group.*by': 'group_by',
            r'find.*maximum|find.*largest|find.*biggest': 'find_max',
            r'find.*minimum|find.*smallest': 'find_min',
            r'sort.*element|order.*element': 'sort_elements',
            r'remove.*duplicate': 'remove_duplicates',
            r'count.*each|count.*how many': 'count_occurrences',
        }

    # ── Parser ────────────────────────────────────────────────────────────────

    def parse(self, source_code):
        """Parse MindScript source into an execution plan."""
        lines = [l.strip() for l in source_code.strip().split('\n') if l.strip()]
        plan = {
            'given':    {},   # Input data
            'intent':   None, # What to do
            'steps':    [],   # How to do it (optional — can be inferred)
            'output':   None, # Expected output description
        }

        i = 0
        while i < len(lines):
            line = lines[i]

            # Parse Given: data declarations
            if line.lower().startswith('given:'):
                data_str = line[6:].strip()
                for decl in data_str.split(','):
                    decl = decl.strip()
                    if '=' in decl:
                        name, val = decl.split('=', 1)
                        plan['given'][name.strip()] = self._parse_value(val.strip())

            # Parse intent from "Find ...", "Check ...", "Count ..." directives
            elif re.match(r'^(find|check|count|sort|group|remove|return|calculate|determine)\b',
                         line.lower()):
                if not plan['intent']:
                    plan['intent'] = line

            # Parse bullet-point algorithm steps
            elif line.startswith('-'):
                plan['steps'].append(line[1:].strip())

            i += 1

        return plan

    def _parse_value(self, val_str):
        """Parse a value string into a Python object."""
        val_str = val_str.strip()
        try:
            # Try Python literal eval (handles lists, numbers, strings)
            return ast.literal_eval(val_str)
        except (ValueError, SyntaxError):
            # Remove surrounding quotes if present
            if (val_str.startswith('"') and val_str.endswith('"')) or \
               (val_str.startswith("'") and val_str.endswith("'")):
                return val_str[1:-1]
            return val_str

    # ── Pattern Matching ──────────────────────────────────────────────────────

    def detect_pattern(self, plan):
        """Detect which algorithm pattern matches the intent."""
        intent_text = ' '.join([
            plan.get('intent', '') or '',
            ' '.join(plan.get('steps', []))
        ]).lower()

        for pattern_regex, pattern_name in self.intent_map.items():
            if re.search(pattern_regex, intent_text, re.IGNORECASE):
                return pattern_name

        return 'unknown'

    # ── Execution ─────────────────────────────────────────────────────────────

    def execute(self, plan):
        """Execute the detected pattern with the given data."""
        pattern_name = self.detect_pattern(plan)

        if pattern_name == 'unknown':
            return self._fallback_execution(plan)

        if pattern_name in self.patterns:
            return self.patterns[pattern_name](plan['given'])

        return {'error': f'Pattern "{pattern_name}" not yet implemented in v0.0.0.1'}

    def compile_and_run(self, source_code):
        """Full pipeline: parse → detect → execute → return result."""
        plan  = self.parse(source_code)
        result = self.execute(plan)
        return {
            'plan':    plan,
            'pattern': self.detect_pattern(plan),
            'result':  result,
        }

    # ── Pattern Implementations ───────────────────────────────────────────────

    def _pattern_find_duplicates(self, data):
        """Find all repeated elements and their counts."""
        arr = self._get_array(data)
        if arr is None:
            return {'error': 'No array input found'}
        counts = Counter(arr)
        duplicates = {k: v for k, v in counts.items() if v > 1}
        return {
            'duplicates':    duplicates,
            'unique_count':  len(counts),
            'total_elements': len(arr),
        }

    def _pattern_two_sum(self, data):
        """Find two numbers that sum to target. Returns their indices."""
        arr    = self._get_array(data)
        target = data.get('target')
        if arr is None or target is None:
            return {'error': 'Need array and target'}
        seen = {}
        for i, num in enumerate(arr):
            complement = target - num
            if complement in seen:
                return {'indices': [seen[complement], i], 'values': [complement, num]}
            seen[num] = i
        return {'result': 'No two numbers sum to target'}

    def _pattern_longest_consecutive(self, data):
        """Find the longest consecutive sequence in an array."""
        arr = self._get_array(data)
        if arr is None:
            return {'error': 'No array input found'}
        num_set     = set(arr)
        best_start  = 0
        best_length = 0
        for num in num_set:
            if num - 1 not in num_set:
                cur, length = num, 1
                while cur + 1 in num_set:
                    cur += 1
                    length += 1
                if length > best_length:
                    best_start, best_length = num, length
        return {
            'length':   best_length,
            'sequence': list(range(best_start, best_start + best_length)),
        }

    def _pattern_valid_parentheses(self, data):
        """Check if parentheses string is balanced."""
        s = data.get('string', '')
        pairs = {')': '(', ']': '[', '}': '{'}
        stack = []
        for char in s:
            if char in '([{':
                stack.append(char)
            elif char in ')]}':
                if not stack or stack[-1] != pairs[char]:
                    return {'balanced': False, 'reason': f'Unmatched "{char}"'}
                stack.pop()
        return {'balanced': len(stack) == 0}

    def _pattern_group_by(self, data):
        """Group array elements by a property."""
        arr = self._get_array(data)
        if arr is None:
            return {'error': 'No array input found'}
        groups = {}
        for item in arr:
            key = type(item).__name__
            groups.setdefault(key, []).append(item)
        return {'groups': groups}

    def _pattern_find_max(self, data):
        arr = self._get_array(data)
        return {'max': max(arr), 'index': arr.index(max(arr))} if arr else {'error': 'No array'}

    def _pattern_find_min(self, data):
        arr = self._get_array(data)
        return {'min': min(arr), 'index': arr.index(min(arr))} if arr else {'error': 'No array'}

    def _pattern_sort_elements(self, data):
        arr = self._get_array(data)
        return {'sorted': sorted(arr), 'sorted_desc': sorted(arr, reverse=True)} if arr else {'error': 'No array'}

    def _pattern_remove_duplicates(self, data):
        arr = self._get_array(data)
        return {'result': list(dict.fromkeys(arr)), 'removed': len(arr) - len(set(arr))} if arr else {'error': 'No array'}

    def _pattern_count_occurrences(self, data):
        arr = self._get_array(data)
        return dict(Counter(arr)) if arr else {'error': 'No array'}

    def _fallback_execution(self, plan):
        return {
            'warning': 'Pattern not recognized in v0.0.0.1',
            'hint':    'Try phrasing your intent as: "Find all X", "Check if Y", "Count Z"',
            'plan':    str(plan),
        }

    # ── Helpers ───────────────────────────────────────────────────────────────

    def _get_array(self, data):
        """Extract the first array-type value from data."""
        for key, val in data.items():
            if isinstance(val, list):
                return val
        return None


# ── CLI Entry Point ───────────────────────────────────────────────────────────

def main():
    if len(sys.argv) < 2:
        print("MindScript Compiler v0.0.0.1")
        print("Founded by Abdul Baays Fakhri — Vancouver, BC, Canada")
        print()
        print("Usage: mindscript <program.ms>")
        print("       python3 mindscript_compiler.py <program.ms>")
        sys.exit(0)

    filename = sys.argv[1]
    if not filename.endswith('.ms'):
        print(f"Warning: MindScript files should use the .ms extension (got: {filename})")

    try:
        with open(filename, 'r') as f:
            source = f.read()
    except FileNotFoundError:
        print(f"Error: File '{filename}' not found.")
        sys.exit(1)

    compiler = MindScriptCompiler()
    output   = compiler.compile_and_run(source)

    print(f"\n=== MindScript Output ===")
    print(f"Pattern detected: {output['pattern']}")
    print(f"Result: {output['result']}")
    print()


if __name__ == '__main__':
    main()
